<?php
/**
 * tests/test_received_invoice_payment_money_safety_cli.php
 *   php tests/test_received_invoice_payment_money_safety_cli.php
 *
 * Step 8 — received_invoices.php (record_payment action, money OUT): the supplier-
 * invoice payment posts loud (postOutflowOrFail), catches MoneyPostingException,
 * and surfaces the I3 funds note (never blocks).
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p = false; if ($p) return; $p = true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$h = file_get_contents("$root/api/received_invoices.php");

section('1. File lint-clean');
$out = shell_exec('php -l ' . escapeshellarg("$root/api/received_invoices.php") . ' 2>&1');
(strpos((string)$out, 'No syntax errors') !== false) ? pass('received_invoices.php lint-clean') : fail($out);

section('2. Supplier-invoice payment posts loud');
(strpos($h, 'core/money_guard.php') !== false) ? pass('includes money_guard foundation') : fail('money_guard.php not included');
(strpos($h, "postOutflowOrFail(\n            \$pdo, 'received_invoice_payment'") !== false
 || preg_match('/postOutflowOrFail\(\s*\$pdo,\s*\047received_invoice_payment\047/', $h) === 1)
    ? pass("record_payment uses postOutflowOrFail() for 'received_invoice_payment'")
    : fail('not using postOutflowOrFail() for the invoice payment');
(preg_match("/=\s*postOutflow\s*\(\s*\\\$pdo,\s*'received_invoice_payment'/", $h) === 0)
    ? pass('the old unchecked postOutflow() call is gone')
    : fail('a bare postOutflow() result is still ignored');

section('3. MoneyPostingException is caught in the record_payment action');
// The record_payment action catch was PDOException-only; a MoneyPostingException would
// have escaped uncaught with no rollback. It must now be caught.
(strpos($h, 'catch (MoneyPostingException') !== false)
    ? pass('record_payment catches MoneyPostingException → rolls back + real reason')
    : fail('MoneyPostingException would escape the catch (no rollback)');

section('4. I3 funds note (warn but allow)');
(strpos($h, 'accountFundsWarning(') !== false) ? pass('computes a funds warning') : fail('no funds warning computed');
(strpos($h, "'funds_warning'") !== false) ? pass('surfaces funds_warning in the response') : fail('funds_warning not returned');
(strpos($h, '$warn_note') !== false) ? pass('appends the warning to the user message') : fail('warning not shown to the user');

section('5. Account still mandatory (unchanged guarantee)');
(strpos($h, 'Please choose the account the payment was made from (Paid From)') !== false)
    ? pass('Paid-From account still mandatory') : fail('lost the Paid-From requirement');
