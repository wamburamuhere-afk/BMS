<?php
/**
 * tests/test_record_payment_money_safety_cli.php
 *   php tests/test_record_payment_money_safety_cli.php
 *
 * Step 4 — record_payment.php (single-invoice payment) can no longer record a
 * COMPLETED payment silently: the received-into account is mandatory and the AR
 * post fails loudly with the real reason.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/money_in_posting.php";   // depositPostReasonMessage
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

$handler = file_get_contents("$root/api/account/record_payment.php");
$form    = file_get_contents("$root/app/bms/invoice/payment_create.php");

// ── 1. Lint ──────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
foreach (['api/account/record_payment.php', 'app/bms/invoice/payment_create.php'] as $f) {
    $out = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    (strpos((string)$out, 'No syntax errors') !== false) ? pass("$f lint-clean") : fail("$f: $out");
}

// ── 2. Server: completed payment requires a cash/bank account ────────────────
section('2. Completed payment must land in a real account');
(strpos($handler, 'core/money_guard.php') !== false)
    ? pass('record_payment includes the money_guard foundation')
    : fail('money_guard.php not included');
(strpos($handler, "requireCashBankAccount(\$pdo, \$received_into_account_id") !== false)
    ? pass('requireCashBankAccount() enforced on the received-into account')
    : fail('account not enforced');
(preg_match('/if\s*\(\s*\$status\s*===\s*[\'"]completed[\'"]\s*\)\s*\{\s*\$received_into_account_id\s*=\s*requireCashBankAccount/s', $handler) === 1)
    ? pass('enforcement is gated on status === completed (pending moves no money)')
    : fail('enforcement not correctly gated on completed status');

// ── 3. Server: the ledger post FAILS LOUDLY with the real reason ─────────────
section('3. Server fails loudly when the payment cannot post');
(strpos($handler, "throw new Exception(depositPostReasonMessage") !== false)
    ? pass('post result is checked and throws the SPECIFIC reason (rolls back)')
    : fail('post result not surfaced as a loud, specific error');
(strpos($handler, "'no_received_into_account'") === false)
    ? pass('old silent "no_received_into_account" branch removed')
    : fail('the silent no-account branch is still present');
(strpos($handler, 'mapping_not_configured') === false)
    ? pass('old "recorded but no ledger entry" warning branch removed (no contradiction)')
    : fail('the misleading ledger_warning branch is still present');

// ── 4. Form: account required + asterisk (alert before submit) ───────────────
section('4. Form alerts before submit');
(preg_match('/received_into_account_id"[^>]*\brequired\b/', $form) === 1)
    ? pass('Received-Into <select> is marked required')
    : fail('Received-Into select is not marked required');
(strpos($form, 'Received Into Account <span class="text-danger">*</span>') !== false)
    ? pass('label shows the required asterisk')
    : fail('label missing the required marker');

// ── 5. Runtime: reason mapper still states the real issue ────────────────────
section('5. depositPostReasonMessage states the real issue');
(stripos(depositPostReasonMessage('credit_account_not_configured'), 'Accounts Receivable') !== false)
    ? pass('credit_account_not_configured → names the AR control account')
    : fail('AR reason message unclear');
(stripos(depositPostReasonMessage('wht_exceeds_amount'), 'withheld tax') !== false)
    ? pass('wht_exceeds_amount → clear message')
    : fail('WHT reason message unclear');
