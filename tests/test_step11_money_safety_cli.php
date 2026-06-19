<?php
/**
 * tests/test_step11_money_safety_cli.php
 *   php tests/test_step11_money_safety_cli.php
 *
 * Step 11 — the four money handlers the audit hadn't fully read are now safe:
 *   - update_payroll_status.php : the cash settlement post is checked + loud (was a
 *     silent `if ($payroll_txn)`); funds warning added.
 *   - remit_statutory.php       : wrapped in one transaction (+rollback); funds warning.
 *   - pay_credit_note.php       : transaction + postOutflowOrFail (specific reason); funds warning.
 *   - pay_debit_note.php        : transaction + postInflowOrFail (specific reason).
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

$files = [
    'payroll'   => "$root/api/update_payroll_status.php",
    'statutory' => "$root/api/remit_statutory.php",
    'credit'    => "$root/api/sales/pay_credit_note.php",
    'debit'     => "$root/api/purchase/pay_debit_note.php",
];
$src = [];
section('1. All four files lint-clean');
foreach ($files as $k => $f) {
    $out = shell_exec('php -l ' . escapeshellarg($f) . ' 2>&1');
    (strpos((string)$out, 'No syntax errors') !== false) ? pass(basename($f) . ' lint-clean') : fail(basename($f) . ": $out");
    $src[$k] = file_get_contents($f);
}

section('2. Payroll pay: loud post + funds warn (no silent if($payroll_txn))');
(preg_match('/if\s*\(\s*!\s*\$payroll_txn\s*\)\s*\{\s*throw/', $src['payroll']) === 1)
    ? pass('payroll throws when the settlement post is null') : fail('payroll still ignores a null settlement');
(preg_match('/if\s*\(\s*\$payroll_txn\s*\)\s*\{/', $src['payroll']) === 0)
    ? pass('old silent "if ($payroll_txn)" guard removed') : fail('old silent guard remains');
(strpos($src['payroll'], 'accountFundsWarning(') !== false && strpos($src['payroll'], "'funds_warning'") !== false)
    ? pass('payroll computes + surfaces a funds warning') : fail('payroll missing funds warning');
(stripos($src['payroll'], 'no ledger entry was created') === false)
    ? pass('misleading "marked paid but no ledger entry" warning removed') : fail('misleading warning still present');

section('3. Statutory: atomic (+rollback) + funds warn (loud post kept)');
(strpos($src['statutory'], '$pdo->beginTransaction()') !== false && strpos($src['statutory'], '$pdo->commit()') !== false)
    ? pass('statutory wrapped in a transaction') : fail('statutory has no transaction');
(preg_match('/catch[^{]*\{[^}]*inTransaction\(\)[^}]*rollBack\(\)/s', $src['statutory']) === 1)
    ? pass('statutory catch rolls back') : fail('statutory catch does not roll back');
(strpos($src['statutory'], "empty(\$res['success'])") !== false)
    ? pass('statutory still checks the post result (loud)') : fail('statutory lost its loud post check');
(strpos($src['statutory'], 'accountFundsWarning(') !== false)
    ? pass('statutory computes a funds warning') : fail('statutory missing funds warning');

section('4. Credit-note refund (OUT): transaction + OrFail + funds warn');
(strpos($src['credit'], 'postOutflowOrFail(') !== false && preg_match('/=\s*postOutflow\s*\(/', $src['credit']) === 0)
    ? pass('credit note uses postOutflowOrFail() (specific reason)') : fail('credit note not using postOutflowOrFail()');
(strpos($src['credit'], '$pdo->beginTransaction()') !== false && strpos($src['credit'], '$pdo->commit()') !== false)
    ? pass('credit note wrapped in a transaction') : fail('credit note has no transaction');
(preg_match('/catch[^{]*\{[^}]*rollBack\(\)/s', $src['credit']) === 1)
    ? pass('credit note catch rolls back') : fail('credit note catch does not roll back');
(strpos($src['credit'], 'accountFundsWarning(') !== false)
    ? pass('credit note computes a funds warning') : fail('credit note missing funds warning');

section('5. Debit-note refund (IN): transaction + InflowOrFail');
(strpos($src['debit'], 'postInflowOrFail(') !== false && preg_match('/=\s*postInflow\s*\(/', $src['debit']) === 0)
    ? pass('debit note uses postInflowOrFail() (specific reason)') : fail('debit note not using postInflowOrFail()');
(strpos($src['debit'], '$pdo->beginTransaction()') !== false && strpos($src['debit'], '$pdo->commit()') !== false)
    ? pass('debit note wrapped in a transaction') : fail('debit note has no transaction');
(preg_match('/catch[^{]*\{[^}]*rollBack\(\)/s', $src['debit']) === 1)
    ? pass('debit note catch rolls back') : fail('debit note catch does not roll back');
