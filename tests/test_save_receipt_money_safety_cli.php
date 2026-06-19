<?php
/**
 * tests/test_save_receipt_money_safety_cli.php
 *   php tests/test_save_receipt_money_safety_cli.php
 *
 * Step 3 — save_receipt.php can no longer record a customer receipt silently.
 * Proves: the received-into account is mandatory (server + form), the ledger post
 * is checked and FAILS LOUDLY with the real reason, and the reason mapper works.
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

$handler = file_get_contents("$root/api/account/save_receipt.php");
$form    = file_get_contents("$root/app/constant/accounts/receive_payment.php");

// ── 1. Lint ──────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
foreach (['api/account/save_receipt.php', 'core/money_in_posting.php', 'app/constant/accounts/receive_payment.php'] as $f) {
    $out = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    (strpos((string)$out, 'No syntax errors') !== false) ? pass("$f lint-clean") : fail("$f: $out");
}

// ── 2. Server: received-into account is MANDATORY ────────────────────────────
section('2. Server makes the account mandatory (no optional/silent path)');
(strpos($handler, 'requireCashBankAccount($pdo, $bank_acc') !== false)
    ? pass('save_receipt calls requireCashBankAccount() on the received-into account')
    : fail('save_receipt does NOT enforce a cash/bank account');
(strpos($handler, "core/money_guard.php") !== false)
    ? pass('save_receipt includes the money_guard foundation')
    : fail('money_guard.php not included');
// The old "validate only if supplied" silent path must be gone.
(strpos($handler, 'Validate the received-into account if supplied') === false)
    ? pass('old "validate only if supplied" optional path removed')
    : fail('the optional validation path is still present');

// ── 3. Server: the ledger post FAILS LOUDLY with the real reason ─────────────
section('3. Server fails loudly when the receipt cannot post');
(strpos($handler, 'depositPostReasonMessage(') !== false && strpos($handler, "throw new Exception(depositPostReasonMessage") !== false)
    ? pass('post result is checked and throws the SPECIFIC reason (rolls back)')
    : fail('post result is not surfaced as a loud, specific error');
// The old fire-and-forget call (postPaymentReceived inside `if ($bank_acc !== null)`, result ignored) must be gone.
(preg_match('/if\s*\(\s*\$bank_acc\s*!==\s*null\s*\)\s*\{\s*require_once[^}]*postPaymentReceived/s', $handler) === 0)
    ? pass('old fire-and-forget posting guard removed')
    : fail('posting still wrapped in the old optional guard (result ignored)');

// ── 4. Form: the field is required + guarded in JS (alert before submit) ─────
section('4. Form alerts before submit');
(preg_match('/received_into_account_id"[^>]*\brequired\b/', $form) === 1)
    ? pass('Received-Into <select> is marked required')
    : fail('Received-Into select is not marked required');
(strpos($form, "Received Into <span class=\"text-danger\">*</span>") !== false)
    ? pass('label shows the required asterisk')
    : fail('label missing the required marker');
(strpos($form, "!\$('#f-bank').val()") !== false || strpos($form, "!$('#f-bank').val()") !== false)
    ? pass('JS save handler blocks submit when no account is chosen')
    : fail('JS save handler does not guard the bank account');

// ── 5. Runtime: the reason mapper states the real issue ──────────────────────
section('5. depositPostReasonMessage states the real issue');
(stripos(depositPostReasonMessage('credit_account_not_configured'), 'Accounts Receivable') !== false)
    ? pass('credit_account_not_configured → names the AR control account')
    : fail('AR reason message unclear');
(stripos(depositPostReasonMessage('bank_account_invalid'), 'not an active account') !== false)
    ? pass('bank_account_invalid → clear message')
    : fail('bank reason message unclear');
(stripos(depositPostReasonMessage('some_new_code'), 'some_new_code') !== false)
    ? pass('unknown reason still surfaces the raw code (never silent)')
    : fail('unknown reason swallowed');
