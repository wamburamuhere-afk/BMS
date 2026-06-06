<?php
/**
 * Chart of Accounts upgrade — Phase 7 (tree / lock / pills) CLI test
 * ------------------------------------------------------------------
 *   php tests/test_coa_tree_phase7_cli.php
 *
 * Static wiring checks for the DataTable renderers on chart_of_accounts.php:
 *   - account name indents by level (padding-left from row.level)
 *   - a lock icon shows for is_system rows
 *   - the Type cell shows a Dr/Cr pill from normal_balance
 *   - Edit/Delete are suppressed for system rows; View Details kept
 *
 * NOTE: actual rendering still needs a browser smoke check. Exit 0 = pass.
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

section('2. Tree indentation by level');
ok(strpos($s, 'row.level') !== false, 'renderer reads row.level');
ok(strpos($s, "padding-left:\${pad}px") !== false || strpos($s, 'padding-left:${pad}px') !== false, 'name cell padded by depth');
ok(strpos($s, '(lvl - 1) * 22') !== false, 'indent = (level-1) * 22px');

section('3. System-account lock icon');
ok(strpos($s, 'row.is_system') !== false, 'renderer reads row.is_system');
ok(strpos($s, 'bi-lock-fill') !== false, 'lock icon used');

section('4. Debit/Credit pill in Type cell');
ok(strpos($s, 'row.normal_balance') !== false, 'Type renderer reads normal_balance');
ok(strpos($s, '>Dr<') !== false, 'Dr pill present');
ok(strpos($s, '>Cr<') !== false, 'Cr pill present');

section('5. Actions: edit/delete suppressed for system accounts');
ok(strpos($s, 'const locked = parseInt(row.is_system, 10) === 1') !== false, 'actions compute locked flag');
ok(strpos($s, 'userPermissions.canEdit && !locked') !== false, 'Edit hidden when locked');
ok(strpos($s, 'userPermissions.canDelete && !locked') !== false, 'Delete hidden when locked');
ok(strpos($s, 'System account — protected') !== false, 'shows protected note on system rows');
ok(strpos($s, 'View Details') !== false, 'View Details still available');

exit($fail === 0 ? 0 : 1);
