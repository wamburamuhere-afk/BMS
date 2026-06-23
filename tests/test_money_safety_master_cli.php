<?php
/**
 * tests/test_money_safety_master_cli.php
 *   php tests/test_money_safety_master_cli.php
 *
 * Step 12 — the CI completeness guard for the "no money moves silently" work.
 *
 * This is the durable safety net: it codifies the hardened pattern across EVERY money
 * in/out handler, so a future change that silently reopens the gap — reverting to a
 * fire-and-forget postOutflow/postInflow, dropping a transaction wrap, dropping the
 * mandatory-account check, or re-introducing a hard funds block — FAILS THE BUILD.
 *
 * It is deterministic (source-level), so it never false-fails on legacy data the way a
 * "every historical row has a ledger link" scan would (supplier_payments + payroll carry
 * known pre-hardening null links).
 *
 * Invariant for every money movement: a valid cash/bank account is mandatory, the post
 * is checked (loud + specific reason), the write is atomic, and low funds WARN (never block).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/money_guard.php";

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$G = [];
function srcOf(string $root, string $rel): string {
    global $G;
    if (!isset($G[$rel])) $G[$rel] = @file_get_contents("$root/$rel") ?: '';
    return $G[$rel];
}
function must(bool $cond, string $label): void { $cond ? pass($label) : fail($label); }
function has(string $h, string $needle): bool { return strpos($h, $needle) !== false; }
function rx(string $h, string $pat): bool { return preg_match($pat, $h) === 1; }

// ── 0. The foundation itself ─────────────────────────────────────────────────
section('0. money_guard foundation intact');
must(function_exists('requireCashBankAccount'), 'requireCashBankAccount() defined');
must(function_exists('postOutflowOrFail'),      'postOutflowOrFail() defined');
must(function_exists('postInflowOrFail'),       'postInflowOrFail() defined');
must(function_exists('accountFundsWarning'),    'accountFundsWarning() defined');
must(class_exists('MoneyPostingException'),     'MoneyPostingException defined');
// The OrFail wrappers must THROW on a blank account (proves they are not silent).
try { postOutflowOrFail($GLOBALS['pdo'], 't', 0, 1, 100, date('Y-m-d'), 'r', 'd'); fail('postOutflowOrFail did not throw on a blank account'); }
catch (MoneyPostingException $e) { pass('postOutflowOrFail throws on a blank account'); }
catch (Throwable $e) { fail('postOutflowOrFail threw the wrong type: ' . get_class($e)); }

// ── 1. Money-IN handlers: mandatory account + loud post ──────────────────────
section('1. Money-IN handlers are mandatory + loud');
$rcv = srcOf($root, 'api/account/save_receipt.php');
must(has($rcv, 'requireCashBankAccount($pdo, $bank_acc'), 'save_receipt: received-into account mandatory');
must(has($rcv, 'throw new Exception(depositPostReasonMessage'), 'save_receipt: loud post failure (specific reason)');

$rp = srcOf($root, 'api/account/record_payment.php');
must(has($rp, 'requireCashBankAccount($pdo, $received_into_account_id'), 'record_payment: account mandatory on completed');
must(has($rp, 'throw new Exception(depositPostReasonMessage'), 'record_payment: loud post failure (specific reason)');
must(!has($rp, "'no_received_into_account'"), 'record_payment: silent no-account branch gone');

$dn = srcOf($root, 'api/purchase/pay_debit_note.php');
must(has($dn, 'postInflowOrFail('), 'pay_debit_note: uses postInflowOrFail()');
must(rx($dn, '/=\s*postInflow\s*\(/') === false, 'pay_debit_note: no bare postInflow()');
must(has($dn, '$pdo->beginTransaction()'), 'pay_debit_note: atomic');

// ── 2. Money-OUT handlers: loud post (no fire-and-forget) ────────────────────
section('2. Money-OUT handlers post loud (no fire-and-forget)');
$outflowHandlers = [
    'api/account/record_voucher_payment.php',
    'api/add_supplier_payment.php',
    'api/sc/add_payment.php',
    'api/received_invoices.php',
    'api/sales/pay_credit_note.php',
];
foreach ($outflowHandlers as $rel) {
    $s = srcOf($root, $rel);
    $b = basename($rel);
    must(has($s, 'postOutflowOrFail('), "$b: uses postOutflowOrFail()");
    // No bare, unchecked `= postOutflow(` assignment remains.
    must(rx($s, '/=\s*postOutflow\s*\(/') === false, "$b: no fire-and-forget postOutflow()");
}

// ── 3. Atomic — handlers that touch multiple rows wrap them in a transaction ─
section('3. Money writes are atomic (+ rollback)');
$atomic = [
    'api/account/record_voucher_payment.php',
    'api/sc/add_payment.php',
    'api/received_invoices.php',
    'api/petty_cash/save_transaction.php',
    'api/remit_statutory.php',
    'api/sales/pay_credit_note.php',
    'api/purchase/pay_debit_note.php',
    'api/update_payroll_status.php',
];
foreach ($atomic as $rel) {
    $s = srcOf($root, $rel);
    $b = basename($rel);
    must(has($s, '$pdo->beginTransaction()') && has($s, '$pdo->commit()'), "$b: wrapped in a transaction");
    must(rx($s, '/catch[^{]*\{[^}]*inTransaction\(\)[^}]*rollBack\(\)/s'), "$b: catch rolls back");
}

// ── 4. petty cash + payroll: explicit loud null-post checks ──────────────────
section('4. Specialised posters fail loudly on a null result');
$pc = srcOf($root, 'api/petty_cash/save_transaction.php');
must(preg_match('/if\s*\(\s*!\s*\$petty_txn\s*\)\s*\{\s*throw new Exception/', $pc) >= 1, 'petty_cash: throws when the post is null');
$pr = srcOf($root, 'api/update_payroll_status.php');
must(rx($pr, '/if\s*\(\s*!\s*\$payroll_txn\s*\)\s*\{\s*throw/'), 'payroll: throws when the settlement post is null');
must(!has($pr, 'no ledger entry was created'), 'payroll: misleading "no ledger entry" warning gone');

// ── 5. I3 "warn but allow": every money-OUT warns, none hard-blocks ──────────
section('5. Low funds WARN, never block (I3)');
$warnHandlers = [
    'api/account/record_voucher_payment.php',
    'api/add_supplier_payment.php',
    'api/sc/add_payment.php',
    'api/received_invoices.php',
    'api/petty_cash/save_transaction.php',
    'api/update_payroll_status.php',
    'api/remit_statutory.php',
    'api/sales/pay_credit_note.php',
    'api/account/add_bank_transfer.php',
    // update_bank_transfer_status.php is now REVERSE-only — it returns money rather
    // than spending it, so it carries no funds warning (auto-post moved the money-out
    // funds check to add_bank_transfer.php above).
];
foreach ($warnHandlers as $rel) {
    $s = srcOf($root, $rel);
    $b = basename($rel);
    must(has($s, 'funds_warning'), "$b: surfaces a funds warning");
}
// The bank-transfer pair must NOT hard-block on a short balance anymore.
must(!has(srcOf($root, 'api/account/add_bank_transfer.php'), "throw new Exception('Insufficient balance"), 'add_bank_transfer: no hard funds block');
must(!has(srcOf($root, 'api/account/update_bank_transfer_status.php'), "throw new Exception('Insufficient balance"), 'update_bank_transfer_status: no hard funds block');
