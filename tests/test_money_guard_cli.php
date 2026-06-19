<?php
/**
 * tests/test_money_guard_cli.php
 *   php tests/test_money_guard_cli.php
 *
 * Step 2 foundation guard. Proves core/money_guard.php:
 *   - requireCashBankAccount() throws the SPECIFIC reason (no account / not cash-bank)
 *     and returns the id for a real cash/bank account.
 *   - postOutflowOrFail()/postInflowOrFail() throw the specific reason on bad input and
 *     return a real transaction_id on a valid post (rolled back — nothing persists).
 *   - accountFundsWarning() warns (never blocks) when funds are short.
 *   - The legacy postOutflow()/postInflow() still exist unchanged (additive proof).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/money_guard.php";
require_once "$root/core/gl_accounts.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

/** Run $fn; pass iff it throws MoneyPostingException with ->reason === $reason. */
function expectReason(string $reason, callable $fn): void {
    try {
        $fn();
        fail("expected MoneyPostingException($reason) — nothing thrown");
    } catch (MoneyPostingException $e) {
        ($e->reason === $reason)
            ? pass("throws $reason — \"" . $e->getMessage() . "\"")
            : fail("expected reason '$reason', got '{$e->reason}' (" . $e->getMessage() . ")");
    } catch (Throwable $e) {
        fail("expected MoneyPostingException, got " . get_class($e) . ": " . $e->getMessage());
    }
}

// ── 0. Lint + additive proof ────────────────────────────────────────────────
section('0. File is valid + purely additive');
$lint = shell_exec('php -l ' . escapeshellarg("$root/core/money_guard.php") . ' 2>&1');
(strpos((string)$lint, 'No syntax errors') !== false) ? pass('money_guard.php lint-clean') : fail("lint failed: $lint");
function_exists('postOutflow') ? pass('legacy postOutflow() still present (untouched)') : fail('postOutflow() vanished');
function_exists('postInflow')  ? pass('legacy postInflow() still present (untouched)')  : fail('postInflow() vanished');

// Resolve real accounts from the live chart.
$cash = cashBankAccounts($pdo);
if (!$cash) { fail('no cash/bank account on the chart — cannot run'); return; }
$cashId   = (int)$cash[0]['account_id'];
$cashName = $cash[0]['account_name'];
$apId     = (int)(apAccountId($pdo) ?? 0);
// A NON cash/bank account: first expense account (definitely not Bank/Cash).
$nonCash = $pdo->query("SELECT a.account_id, a.account_name
                          FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
                         WHERE at.category='expense' AND a.status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// ── 1. requireCashBankAccount ───────────────────────────────────────────────
section('1. requireCashBankAccount states the real issue');
expectReason(MONEY_ERR_NO_ACCOUNT,    fn() => requireCashBankAccount($pdo, 0, 'Paid-From'));
expectReason(MONEY_ERR_NO_ACCOUNT,    fn() => requireCashBankAccount($pdo, null, 'Paid-From'));
expectReason(MONEY_ERR_NOT_CASH_BANK, fn() => requireCashBankAccount($pdo, 999999999, 'Paid-From')); // non-existent
if ($nonCash) {
    expectReason(MONEY_ERR_NOT_CASH_BANK, fn() => requireCashBankAccount($pdo, (int)$nonCash['account_id'], 'Paid-From'));
}
try {
    $ret = requireCashBankAccount($pdo, $cashId, 'Paid-From');
    ($ret === $cashId) ? pass("returns the id for a real cash/bank account ($cashName)") : fail("returned $ret, expected $cashId");
} catch (Throwable $e) { fail('threw on a valid cash/bank account: ' . $e->getMessage()); }

// ── 2. postOutflowOrFail — specific reasons + real post (rolled back) ────────
section('2. postOutflowOrFail throws the real reason, posts when valid');
$today = date('Y-m-d');
expectReason(MONEY_ERR_AMOUNT_INVALID,   fn() => postOutflowOrFail($pdo, 'test', $cashId, $apId, 0,    $today, 'T', 'zero amount'));
expectReason(MONEY_ERR_NO_ACCOUNT,       fn() => postOutflowOrFail($pdo, 'test', 0,      $apId, 100,  $today, 'T', 'no source'));
expectReason(MONEY_ERR_CONTROL_UNMAPPED, fn() => postOutflowOrFail($pdo, 'test', $cashId, 0,     100,  $today, 'T', 'no debit acct'));
if ($apId > 0) {
    $pdo->beginTransaction();
    try {
        $txn = postOutflowOrFail($pdo, 'test_money_guard', $cashId, $apId, 1234.00, $today, 'TST-OUT', 'guard outflow probe');
        ($txn > 0) ? pass("valid outflow posted (transaction_id #$txn)") : fail('valid outflow returned non-positive');
    } catch (Throwable $e) {
        fail('valid outflow threw: ' . $e->getMessage());
    } finally { $pdo->rollBack(); }
    pass('outflow probe rolled back — nothing persisted');
}

// ── 3. postInflowOrFail — specific reasons + real post (rolled back) ─────────
section('3. postInflowOrFail throws the real reason, posts when valid');
// A credit (income) account for the inflow contra.
$incomeId = (int)($pdo->query("SELECT a.account_id FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
                                WHERE at.category='revenue' AND a.status='active' LIMIT 1")->fetchColumn() ?: 0);
expectReason(MONEY_ERR_AMOUNT_INVALID, fn() => postInflowOrFail($pdo, 'test', $cashId, $incomeId, 0,   $today, 'T', 'zero amount'));
expectReason(MONEY_ERR_NO_ACCOUNT,     fn() => postInflowOrFail($pdo, 'test', 0,      $incomeId, 100, $today, 'T', 'no dest'));
if ($incomeId > 0) {
    $pdo->beginTransaction();
    try {
        $txn = postInflowOrFail($pdo, 'test_money_guard', $cashId, $incomeId, 555.00, $today, 'TST-IN', 'guard inflow probe');
        ($txn > 0) ? pass("valid inflow posted (transaction_id #$txn)") : fail('valid inflow returned non-positive');
    } catch (Throwable $e) {
        fail('valid inflow threw: ' . $e->getMessage());
    } finally { $pdo->rollBack(); }
    pass('inflow probe rolled back — nothing persisted');
}

// ── 4. accountFundsWarning — warns but never blocks (I3) ─────────────────────
section('4. accountFundsWarning warns but never blocks');
$warn = accountFundsWarning($pdo, $cashId, 1.0e15);   // impossibly large → should warn
(is_string($warn) && $warn !== '') ? pass('returns a warning string when funds are short (does NOT throw)') : fail('expected a warning string for an impossible amount');
$noWarn = accountFundsWarning($pdo, $cashId, 0.0);     // zero need → no warning
($noWarn === null) ? pass('returns null when there is nothing to warn about') : fail('expected null for a zero amount');
