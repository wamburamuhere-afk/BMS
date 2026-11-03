<?php
/**
 * Activity history permission + shop-mode "Sell" label — CLI test
 *   php tests/test_activity_log_permission_and_sell_label_cli.php
 *
 * 2026-10-12 request:
 *   1. Activity history (dashboard Recent Activities, Audit Logs page, Activity
 *      Log page, old Audit Report link) is controlled by ONE role permission,
 *      "Audit Logs" (audit_logs), shown in the Reports tab. The duplicate
 *      "Audit Report" / "Activity Log" boxes are retired from the matrix, so
 *      unticking the one box really hides everything.
 *   2. Stored activity texts like "Logged in to the system" display translated.
 *   3. In shop mode the POS menu/bottom-bar/heading reads "Sell" ("Uza");
 *      a full ERP company keeps "POS".
 *   4. Dashboard chart code no longer throws when the chart card is hidden.
 *
 * No HTTP, no DB. Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
if (!defined('ROOT_DIR')) define('ROOT_DIR', $root);
require_once "$root/core/i18n.php";
require_once "$root/core/feature_registry.php";
require_once "$root/core/terminology.php";
require_once "$root/core/activity_log_helpers.php";
require_once "$root/core/role_permission_ui.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});
if (!function_exists('get_setting')) {
    function get_setting(string $key, $default = '') { return $default; }
}
$src = fn(string $f) => (string)file_get_contents("$root/$f");

section('1. Lint');
foreach (['app/activity_log.php', 'app/dashboard.php', 'app/constant/reports/audit_report.php', 'core/activity_log_helpers.php', 'core/permissions.php', 'core/role_permission_ui.php', 'header.php', 'app/bms/pos/pos.php', 'app/bms/pos/pos_dashboard.php', 'app/bms/pos/shift_history.php', 'lang/sw.php'] as $f) {
    ok(strpos((string)shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1'), 'No syntax errors') !== false, "$f lints clean");
}

section('2. One permission for activity history');
$retired = rolePermissionRetiredKeys();
ok(in_array('audit_report', $retired, true) && in_array('activity_log', $retired, true), 'duplicate "Audit Report" / "Activity Log" boxes retired from the matrix');
ok(!in_array('audit_logs', $retired, true), '"Audit Logs" box still shown');
ok(rolePermissionTabName('audit_logs', 'System Settings') === 'Reports', '"Audit Logs" box sits in the Reports tab');
ok(strpos($src('app/constant/reports/audit_report.php'), "autoEnforcePermission('audit_logs')") !== false, 'Audit Report page enforces audit_logs (was financial_reports)');
ok(strpos($src('core/permissions.php'), "'audit_report.php'         => 'audit_logs'") !== false, 'file-to-permission map: audit_report.php -> audit_logs');
ok(strpos($src('header.php'), "canView('audit_report')") === false, 'menu: duplicate Audit Report link removed');
ok(strpos($src('header.php'), "<?php if(canView('audit_logs')): ?><a class=\"dropdown-item\" href=\"<?= getUrl('audit_logs') ?>\"") !== false, 'menu: Audit Logs link gated on audit_logs');
$dash = $src('app/dashboard.php');
ok(strpos($dash, "if (canView('audit_logs')) {\n    \$recent_activities = get_recent_activities(") !== false, 'dashboard: activities only loaded with audit_logs');
ok(strpos($src('app/activity_log.php'), "autoEnforcePermission('audit_logs');") !== false, 'Activity Log page enforces audit_logs');
ok(strpos($src('app/constant/reports/audit_logs.php'), "autoEnforcePermission('audit_logs');") !== false, 'Audit Logs page enforces audit_logs');

section('3. Activity texts translated on display');
loadLanguage('sw');
ok(activityDisplayText('Logged in to the system') === 'Ameingia kwenye mfumo', 'login text -> Swahili');
ok(activityDisplayText('Logged out of the system — session lasted 2h 5m') === 'Ametoka kwenye mfumo — alikaa kwa 2h 5m', 'logout text (with duration) -> Swahili');
ok(activityDisplayText('User edited warehouse: Mbezi (WH-1)') === 'User edited warehouse: Mbezi (WH-1)', 'unknown text shown exactly as stored');
loadLanguage('en');
ok(activityDisplayText('Logged in to the system') === 'Logged in to the system', 'English unchanged');
ok(strpos($dash, 'htmlspecialchars(activityDisplayText((string)$displayText))') !== false, 'dashboard uses activityDisplayText()');
ok(substr_count($src('app/activity_log.php'), 'htmlspecialchars(activityDisplayText(') === 2, 'Activity Log page (table + AJAX) uses activityDisplayText()');

section('4. "Sell" / "Uza" in shop mode only');
$shop = array_merge(array_fill_keys(allFeatureKeys(), true), ['pos' => true, 'projects' => false]);
$erp  = array_merge(array_fill_keys(allFeatureKeys(), true), ['pos' => true, 'projects' => true]);
$GLOBALS['__bms_features'] = $shop;
ok(wLabel('POS', 'Sell') === 'Sell' && wLabel('Point of Sale', 'Sell') === 'Sell', 'shop mode, English: "Sell"');
loadLanguage('sw');
ok(wLabel('POS', 'Sell') === 'Uza', 'shop mode, Swahili: "Uza"');
ok(wLabel('Open POS', 'Start Selling') === 'Anza Kuuza', 'shop mode, Swahili: "Anza Kuuza"');
ok(t('Sale') === 'Uzo', 'existing "Sale" (one sale record) untouched');
loadLanguage('en');
$GLOBALS['__bms_features'] = $erp;
ok(wLabel('POS', 'Sell') === 'POS' && wLabel('Point of Sale', 'Sell') === 'Point of Sale', 'full ERP (Projects on): still "POS"');
unset($GLOBALS['__bms_features']);
ok(substr_count($src('header.php'), "wLabel('POS', 'Sell')") === 3, 'header: all 3 POS links use the shop-mode label');
ok(substr_count($dash, "wLabel('POS', 'Sell')") === 2, 'dashboard: both POS shortcuts use the shop-mode label');
ok(strpos($src('app/bms/pos/pos.php'), "wLabel('Point of Sale', 'Sell')") !== false, 'POS page heading uses the shop-mode label');
ok(strpos($src('header.php'), "t('POS')") === false && strpos($dash, "t('POS')") === false, 'no plain t(\'POS\') left in header/dashboard');

section('5. Dashboard chart guard');
ok(strpos($dash, "if (document.getElementById('performanceChart')) {\n        loadPerformanceChart(") !== false, 'chart only loaded when its card is on the page');
ok(strpos($dash, "function renderChart(data) {\n    if (!document.getElementById('performanceChart')) return;") !== false, 'renderChart() returns early without a canvas');

section('6. Swahili present');
$sw = include "$root/lang/sw.php";
foreach (['Sell', 'Start Selling', 'Logged in to the system', 'Logged out of the system — session lasted %s'] as $k) {
    ok(isset($sw[$k]) && $sw[$k] !== '', "sw.php has \"$k\"");
}
ok(($sw['View Inventory Alerts'] ?? '') === 'Angalia Tahadhari za Stoki', 'dashboard alerts button no longer says Ghala');
ok(strpos($dash, "wLabel(\"Today's POS Sales\", \"Today's Sales\")") !== false && ($sw["Today's Sales"] ?? '') === 'Mauzo ya Leo', 'dashboard card: "Mauzo ya Leo" in shop mode (no "POS")');

section('7. Shop mode: no "POS" left in selling wording');
$pairs = [];
$files = ['header.php', 'app/constant/settings/pos_config_settings.php', 'app/bms/pos/pos_credit_customers.php', 'app/bms/pos/pos_dashboard.php', 'app/bms/pos/pos.php', 'app/bms/customer/customer_details.php', 'app/bms/Suppliers/supplier_details.php', 'app/bms/stock/warehouse_batches_tab.php', 'app/bms/product/services.php', 'app/bms/product/products.php', 'core/pos_nav.php', 'app/constant/settings/help.php', 'app/constant/settings/user_roles.php', 'app/constant/reports/sales_report.php', 'api/pos/close_shift.php', 'api/pos/delete_held_sale.php', 'api/pos/hold_sale.php', 'api/pos/open_shift.php', 'api/pos/process_sale.php', 'api/pos/create_return.php', 'api/pos/receive_payment.php', 'api/pos/void_sale.php'];
$q = "'((?:[^'\\\\]|\\\\.)*)'";
foreach ($files as $f) {
    preg_match_all("/\\bwLabel\\(\\s*$q\\s*,\\s*$q\\s*\\)/", $src($f), $m, PREG_SET_ORDER);
    foreach ($m as $mm) if (preg_match('/\\bPOS\\b|Point of Sale/', stripslashes($mm[1]))) $pairs[stripslashes($mm[1])] = stripslashes($mm[2]);
}
ok(count($pairs) >= 30, count($pairs) . ' POS strings switch to selling wording in shop mode');
$GLOBALS['__bms_features'] = $shop;
$bad = [];
foreach (['en', 'sw'] as $lang) {
    loadLanguage($lang);
    foreach ($pairs as $erpText => $shopText) {
        $out = wLabel($erpText, $shopText);
        if (preg_match('/\\bPOS\\b/', $out) || ($lang === 'sw' && !isset($sw[$shopText]))) $bad[] = "$lang: $shopText => $out";
    }
}
ok(empty($bad), 'shop mode (en + sw): none of them shows "POS", all have Swahili' . ($bad ? ' — ' . implode(' | ', array_slice($bad, 0, 5)) : ''));
$GLOBALS['__bms_features'] = $erp;
loadLanguage('en');
$kept = 0; foreach ($pairs as $erpText => $shopText) if (wLabel($erpText, $shopText) === $erpText) $kept++;
ok($kept === count($pairs), 'full ERP: every one keeps its original POS wording');
unset($GLOBALS['__bms_features']);
ok(strpos($src('app/bms/pos/pos_dashboard.php'), "\$page_title = wLabel('POS Workspace', 'Sales Overview');") !== false, 'Sales Overview page title');
ok(strpos($src('app/constant/settings/help.php'), '<span class="small">POS</span>') === false, 'help: no hard-coded "POS" shortcut label');
