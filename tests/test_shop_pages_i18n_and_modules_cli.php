<?php
/**
 * Shop pages — translation + module gating — CLI test
 *   php tests/test_shop_pages_i18n_and_modules_cli.php
 *
 * Covers the shop (warehouse) list page, its Edit form and the shop details
 * page (2026-10-11 request):
 *   1. All touched files lint-clean.
 *   2. COMPLETENESS: every literal passed to t()/te()/wLabel() in these files
 *      has a real Swahili translation in lang/sw.php.
 *   3. No hard-coded English labels left in the Edit form / details page.
 *   4. A module the superadmin switched off leaves no trace: the Project field
 *      is gated on projectsModuleActive(), POS Mode on Restaurant POS, and the
 *      details page drops ?project_id= when Projects is off.
 *   5. The edit handler keeps a stored project / POS mode when the form did not
 *      send that (hidden) field, instead of wiping it.
 *   6. warehouseStatusLabel() / stockMovementLabel() resolve to Shop wording
 *      in shop mode and to Swahili when Swahili is loaded.
 *
 * No HTTP, no DB. Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
if (!defined('ROOT_DIR')) define('ROOT_DIR', $root);
require_once "$root/core/i18n.php";
require_once "$root/core/feature_registry.php";
require_once "$root/core/terminology.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// Pure-logic stand-in for helpers.php's get_setting() (never loaded here).
if (!function_exists('get_setting')) {
    function get_setting(string $key, $default = '') { return $default; }
}

$files = [
    'ajax_get_warehouse.php',
    'app/bms/stock/warehouses.php',
    'app/bms/stock/warehouse_view.php',
    'core/terminology.php',
    'lang/sw.php',
];

section('1. Lint');
foreach ($files as $f) {
    $out = shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1');
    ok(strpos((string)$out, 'No syntax errors') !== false, "$f lints clean");
}

section('2. Every translatable string has Swahili');
$sw = include "$root/lang/sw.php";
$q  = "'((?:[^'\\\\]|\\\\.)*)'";
$keys = [];
foreach (['ajax_get_warehouse.php', 'app/bms/stock/warehouses.php', 'app/bms/stock/warehouse_view.php', 'app/bms/stock/warehouse_batches_tab.php', 'core/terminology.php'] as $f) {
    $src = file_get_contents("$root/$f");
    preg_match_all("/\\bwLabelE?\\(\\s*$q\\s*,\\s*$q/", $src, $m);
    foreach (array_merge($m[1], $m[2]) as $k) $keys[stripslashes($k)] = $f;
    preg_match_all('/\\bwLabelE?\\(\\s*"((?:[^"\\\\]|\\\\.)*)"\\s*,\\s*"((?:[^"\\\\]|\\\\.)*)"/', $src, $m);
    foreach (array_merge($m[1], $m[2]) as $k) $keys[stripcslashes($k)] = $f;
    preg_match_all("/\\bte?\\(\\s*$q/", $src, $m);
    foreach ($m[1] as $k) $keys[stripslashes($k)] = $f;
}
$missing = [];
foreach ($keys as $k => $f) {
    if (!isset($sw[$k]) || trim((string)$sw[$k]) === '') $missing[] = "$k ($f)";
}
ok(count($keys) > 150, 'found ' . count($keys) . ' translatable strings');
ok(empty($missing), 'all have Swahili' . ($missing ? ' — MISSING: ' . implode(' | ', $missing) : ''));

section('3. No hard-coded English labels left');
$edit = file_get_contents("$root/ajax_get_warehouse.php");
$view = file_get_contents("$root/app/bms/stock/warehouse_view.php");
ok(!preg_match('/class="form-label">[A-Za-z]/', $edit), 'edit form: every <label> goes through t()/wLabel()');
ok(!preg_match('/<option[^>]*>\\s*[A-Za-z]/', preg_replace('/<\\?=.*?\\?>/s', '', $edit)), 'edit form: every <option> is translated');
foreach (['>Warehouse Name *<', '>Warehouse Code <', '(System assigned)', "\n                    Set as Primary Warehouse\n", "exit('Warehouse not found')", "Link this warehouse to a specific project</small>"] as $s) {
    ok(strpos($edit, $s) === false, 'edit form: no raw ' . trim($s));
}
foreach (['WAREHOUSE INVENTORY REPORT', '>Active Locations<', '>Total Stock Item<', '>Contact Person<', '>Full Address<', '>Storage Locations<', '>Health<', "'Out'", "'Low'", "'Over'", "'Default'", 'Search inventory...', "'Back to List'", ">Current Inventory<", "'Filter Stock:'", "'Warehouse_Inventory.csv'", "ucwords(str_replace('_', ' ', \$mv['movement_type']))"] as $s) {
    ok(strpos($view, $s) === false, "details page: no raw $s");
}

section('4. Switched-off modules leave no trace');
ok(strpos($edit, '$show_projects = projectsModuleActive();') !== false && strpos($edit, '<?php if ($show_projects): ?>') !== false, 'edit form: Project field gated on projectsModuleActive()');
ok(strpos($edit, "\$show_pos_mode = tenantFeatureEnabled('pos') && tenantFeatureEnabled('restaurant_pos');") !== false && strpos($edit, '<?php if ($show_pos_mode): ?>') !== false, 'edit form: POS Mode gated on Restaurant POS');
$list = file_get_contents("$root/app/bms/stock/warehouses.php");
ok(strpos($list, "<?php if (tenantFeatureEnabled('pos') && tenantFeatureEnabled('restaurant_pos')): ?>") !== false, 'add form: POS Mode gated on Restaurant POS');
ok(preg_match('/if \\(!projectsModuleActive\\(\\)\\) \\{\\s*\\$project_id = 0;/', $view) === 1, 'details page: ?project_id= ignored when Projects is off');

section('5. Edit keeps hidden fields');
ok(strpos($list, "if (!array_key_exists('project_id', \$_POST) || !array_key_exists('pos_mode', \$_POST)) {") !== false, 'edit handler reloads stored project / POS mode when not posted');
ok(strpos($list, "?warehouse_id=' + warehouseId") !== false && strpos($list, 'from_warehouse_id') === false, 'list page: Transfer Stock passes the parameter stock_transfers reads');

section('6. Status / activity labels');
$GLOBALS['__bms_features'] = array_merge(array_fill_keys(allFeatureKeys(), true), ['pos' => true, 'projects' => false]);
loadLanguage('en');
ok(warehouseStatusLabel('active') === 'Active' && warehouseStatusLabel('maintenance') === 'Under Maintenance', 'English status labels');
ok(stockMovementLabel('transfer_in') === 'Received from Another Shop', 'shop mode: transfer_in -> "Received from Another Shop"');
ok(stockMovementLabel('sale_out') === 'Sold', "sale_out -> 'Sold'");
ok(stockMovementLabel('weird_code') === 'Weird Code', 'unknown code falls back to readable text');
loadLanguage('sw');
ok(warehouseStatusLabel('active') === 'Linatumika', "Swahili: active -> 'Linatumika' (not 'Hai')");
ok(warehouseStatusLabel('inactive') === 'Halitumiki', "Swahili: inactive -> 'Halitumiki'");
ok(stockMovementLabel('transfer_out') === 'Imetumwa Duka Jingine', 'Swahili shop mode: transfer_out');
ok(wLabel('Warehouse Information', 'Shop Information') === 'Taarifa za Duka', 'Swahili shop mode: info box title');
foreach (['Stock Value', 'Out of stock', 'Transfer Stock', 'Current Stock', 'Low Stock'] as $k) {
    ok(stripos(t($k), 'ghala') === false, "Swahili '$k' no longer says Ghala (" . t($k) . ')');
}
ok(t('Export') !== t('Transfer Stock'), 'Export and Transfer Stock no longer share one Swahili word');
$GLOBALS['__bms_features'] = array_merge(array_fill_keys(allFeatureKeys(), true), ['pos' => true, 'projects' => true]);
ok(stockMovementLabel('transfer_out') === 'Imetumwa Ghala Jingine', 'Swahili warehouse mode (Projects on): transfer_out keeps Ghala');
loadLanguage('en');
