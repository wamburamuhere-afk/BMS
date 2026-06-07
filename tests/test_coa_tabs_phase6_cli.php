<?php
/**
 * Chart of Accounts upgrade — Phase 6 (type tab bar) CLI test
 * -----------------------------------------------------------
 *   php tests/test_coa_tabs_phase6_cli.php
 *
 * Static wiring checks for the type tabs on chart_of_accounts.php:
 *   - all 8 tabs present with the correct data-category values
 *   - currentCategory declared before the table, sent in ajax.data
 *   - tab click handler reloads the table
 *   - the old #accountTypeFilter dropdown is gone (tabs replace it)
 *   - page lints clean
 *
 * NOTE: visual tab filtering still needs a browser smoke check (T4–T5).
 * Exit 0 = pass.
 */

$root = dirname(__DIR__);
$page = 'app/constant/accounts/chart_of_accounts.php';

$pass = 0; $fail = 0;
function ok($c, $m){ global $pass, $fail; if ($c){ $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

$s = is_file("$root/$page") ? file_get_contents("$root/$page") : '';

section('1. Page lints clean');
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("$root/$page") . ' 2>&1', $out, $rc);
ok($rc === 0, "$page lint-clean");

section('2. All 8 type tabs present with correct data-category');
$tabs = [
    ''             => 'All Accounts',
    'asset'        => 'Asset',
    'liability'    => 'Liability',
    'equity'       => 'Equity',
    'revenue'      => 'Income',          // label differs from category on purpose
    'cogs'         => 'Cost of Sales',
    'expense'      => 'Expense',
    'finance_cost' => 'Finance Cost',
];
foreach ($tabs as $cat => $label) {
    ok(strpos($s, 'data-category="' . $cat . '"') !== false, "tab data-category=\"$cat\" ($label) present");
}

section('3. JS wiring');
ok(strpos($s, 'let currentCategory') !== false, 'currentCategory declared');
ok(strpos($s, 'd.category = currentCategory') !== false, 'ajax.data sends category');
ok(preg_match('/\.coa-tabs .nav-link.*\.on\(.click./s', $s) === 1, 'tab click handler wired');
ok(strpos($s, 'currentCategory = $(this).data(\'category\')') !== false, 'tab click sets currentCategory from data-category');

section('4. Old type dropdown removed (tabs replace it)');
ok(strpos($s, 'accountTypeFilter') === false, '#accountTypeFilter dropdown removed');

section('5. Other filters preserved');
ok(strpos($s, 'statusFilter') !== false, 'status filter kept');
ok(strpos($s, 'customSearch') !== false, 'search box kept');

exit($fail === 0 ? 0 : 1);
