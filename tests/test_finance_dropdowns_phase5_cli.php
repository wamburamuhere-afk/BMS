<?php
/**
 * Chart of Accounts upgrade — Phase 5 (finance dropdown standardisation) CLI test
 * -------------------------------------------------------------------------------
 *   php tests/test_finance_dropdowns_phase5_cli.php
 *
 * Confirms the four Finance pages now source their account dropdowns from the
 * canonical helpers (Phase 4) instead of the drift-prone denormalised
 * account_type string / type_name LIKE subquery:
 *   - revenue.php        → incomeAccounts()
 *   - bank_transfers.php → expenseAccounts()
 *   - recurring.php      → expenseAccounts()
 *   - expenses.php       → expenseAccounts()
 * and that each still requires core/payment_source.php and lints clean.
 *
 * Static source checks (page wiring). Exit 0 = pass.
 */

$root = dirname(__DIR__);

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return is_file($p) ? file_get_contents($p) : ''; }

register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

// rel => [ helper that must now be called, old pattern that must be gone ]
$specs = [
    'app/constant/accounts/revenue.php'        => ['incomeAccounts($pdo)',  "account_type = 'income'"],
    'app/constant/accounts/bank_transfers.php' => ['expenseAccounts($pdo)', "account_type = 'expense'"],
    'app/constant/accounts/recurring.php'      => ['expenseAccounts($pdo)', "account_type='expense'"],
    'app/constant/accounts/expenses.php'       => ['expenseAccounts($pdo)', "type_name LIKE '%expense%'"],
];

section('1. Pages lint clean');
foreach ($specs as $rel => $_) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("$root/$rel") . ' 2>&1', $out, $rc);
    ok($rc === 0, "$rel lint-clean");
}

section('2. Each page now calls the canonical helper');
foreach ($specs as $rel => [$helper, $oldPattern]) {
    $s = src($root, $rel);
    ok($s !== '' && strpos($s, $helper) !== false, "$rel calls $helper");
}

section('3. Old drift-prone query removed from each page');
foreach ($specs as $rel => [$helper, $oldPattern]) {
    $s = src($root, $rel);
    ok($s !== '' && strpos($s, $oldPattern) === false, "$rel no longer uses `$oldPattern`");
}

section('4. Each page still requires core/payment_source.php');
foreach ($specs as $rel => $_) {
    $s = src($root, $rel);
    ok(strpos($s, 'payment_source.php') !== false, "$rel requires payment_source.php");
}

exit($fail === 0 ? 0 : 1);
