<?php
/**
 * tests/test_sc_payment_money_safety_cli.php
 *   php tests/test_sc_payment_money_safety_cli.php
 *
 * Step 7 — api/sc/add_payment.php (sub-contractor payment, money OUT): the
 * consolidated outflow is now loud (postOutflowOrFail) instead of fire-and-forget,
 * and the I3 funds note is surfaced (never blocks).
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

$h = file_get_contents("$root/api/sc/add_payment.php");

section('1. File lint-clean');
$out = shell_exec('php -l ' . escapeshellarg("$root/api/sc/add_payment.php") . ' 2>&1');
(strpos((string)$out, 'No syntax errors') !== false) ? pass('sc/add_payment.php lint-clean') : fail($out);

section('2. Posting is loud, not fire-and-forget');
(strpos($h, 'core/money_guard.php') !== false) ? pass('includes money_guard foundation') : fail('money_guard.php not included');
(preg_match('/=\s*postOutflowOrFail\s*\(/', $h) === 1) ? pass('uses postOutflowOrFail() (throws the real reason)') : fail('not using postOutflowOrFail()');
(preg_match('/=\s*postOutflow\s*\(/', $h) === 0) ? pass('the old unchecked postOutflow() call is gone') : fail('a bare postOutflow() result is still ignored');
(preg_match('/if\s*\(\s*\$txn\s*\)/', $h) === 0) ? pass('old "if ($txn)" silent guard removed (id stored unconditionally)') : fail('result still treated as optional');

section('3. I3 funds note (warn but allow)');
(strpos($h, 'accountFundsWarning(') !== false) ? pass('computes a funds warning') : fail('no funds warning computed');
(strpos($h, "'funds_warning'") !== false) ? pass('surfaces funds_warning in the response') : fail('funds_warning not returned');
(stripos($h, 'Insufficient balance') === false) ? pass('does not hard-block on low funds (warn but allow)') : fail('appears to block on low funds');

section('4. Still atomic + account mandatory (unchanged guarantees)');
(strpos($h, '$pdo->beginTransaction()') !== false && strpos($h, '$pdo->commit()') !== false) ? pass('still wrapped in a transaction') : fail('lost the transaction');
(preg_match('/catch[^{]*\{[^}]*rollBack\(\)/s', $h) === 1) ? pass('catch rolls back') : fail('catch does not roll back');
(strpos($h, 'Please choose the account the payment was made from') !== false) ? pass('Paid-From account still mandatory') : fail('lost the Paid-From requirement');
