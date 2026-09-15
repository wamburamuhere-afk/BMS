<?php
/**
 * POS "Shop"/"Duka" terminology — CLI test
 *   php tests/test_pos_shop_terminology_cli.php
 *
 * core/terminology.php's isShopLabel()/wLabel()/wLabelE() let a POS-enabled
 * tenant see "Shop"/"Duka" instead of "Warehouse"/"Ghala", without touching
 * the underlying `warehouses` table, columns, or any query. This test drives
 * the pure resolver logic directly (no HTTP, no DB) across every combination
 * of the two tenant flags it reads (pos, projects) and the isPosCoreScreen
 * flag pos.php/pos_dashboard.php pass, plus both supported languages.
 *
 * Exit 0 = all pass.
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
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

function setFeatures(?bool $pos, ?bool $projects) {
    if ($pos === null && $projects === null) {
        unset($GLOBALS['__bms_features']);
        return;
    }
    $GLOBALS['__bms_features'] = ['pos' => $pos, 'projects' => $projects];
}

// Minimal stand-in for helpers.php's real get_setting(), which this pure-logic
// test deliberately never loads (no HTTP, no DB — see the file docblock).
// Only isShopLabel()'s 'shop_mode' lookup is exercised here.
if (!function_exists('get_setting')) {
    function get_setting(string $key, $default = '') {
        if ($key === 'shop_mode' && array_key_exists('__test_shop_mode', $GLOBALS)) {
            return $GLOBALS['__test_shop_mode'];
        }
        return $default;
    }
}
function setShopModeOverride(?string $val) {
    if ($val === null) { unset($GLOBALS['__test_shop_mode']); return; }
    $GLOBALS['__test_shop_mode'] = $val;
}

loadLanguage('en');

section('POS off — always Warehouse, on every screen (unchanged behavior)');
setFeatures(false, false);
ok(isShopLabel(false) === false, 'isShopLabel(false): pos off, projects off -> false');
ok(isShopLabel(true) === false, 'isShopLabel(true): pos off, projects off -> false (even the POS core screen)');
setFeatures(false, true);
ok(isShopLabel(true) === false, 'isShopLabel(true): pos off, projects on -> still false');

section('POS on, Projects on — Shop only on the POS core screen; rest of the app stays Warehouse');
setFeatures(true, true);
ok(isShopLabel(false) === false, 'isShopLabel(false): pos on, projects on -> Warehouse (Inventory/HR/Reports/etc.)');
ok(isShopLabel(true) === true, 'isShopLabel(true): pos on, projects on -> Shop (pos.php / POS dashboard)');
ok(wLabel('Warehouse', 'Shop') === 'Warehouse', "wLabel() off pos-core screen -> 'Warehouse'");
ok(wLabel('Warehouse', 'Shop', true) === 'Shop', "wLabel() on pos-core screen -> 'Shop'");

section('POS on, Projects off — Shop everywhere the app calls wLabel()');
setFeatures(true, false);
ok(isShopLabel(false) === true, 'isShopLabel(false): pos on, projects off -> Shop');
ok(isShopLabel(true) === true, 'isShopLabel(true): pos on, projects off -> Shop');
ok(wLabel('Warehouse', 'Shop') === 'Shop', "wLabel() off pos-core screen -> 'Shop'");
ok(wLabel('Warehouse', 'Shop', true) === 'Shop', "wLabel() on pos-core screen -> 'Shop'");

section('No tenant resolved (single-tenant install / CLI / platform host) — feature flags default to true');
setFeatures(null, null);
ok(tenantFeatureEnabled('pos') === true, "sanity: tenantFeatureEnabled('pos') defaults true with no tenant resolved");
ok(isShopLabel(false) === false, 'isShopLabel(false) defaults to Warehouse (Projects also defaults on)');
ok(isShopLabel(true) === true, 'isShopLabel(true) still shows Shop on the POS core screen by default');

section('Swahili catalog — Ghala vs Duka pairs actually translated, not just the raw key echoed back');
loadLanguage('sw');
setFeatures(true, true); // projects on -> non-core screens stay Ghala
ok(wLabel('Warehouse required', 'Shop required') === 'Ghala linahitajika', 'sw, non-core, projects on -> Ghala wording');
ok(wLabel('Warehouse required', 'Shop required', true) === 'Duka linahitajika', 'sw, POS core screen -> Duka wording');
setFeatures(true, false); // projects off -> Shop/Duka everywhere
ok(wLabel('— Select Warehouse —', '— Select Shop —') === '— Chagua Duka —', 'sw, projects off, non-core -> Duka wording');
ok(
    wLabel('No warehouse is assigned to your account — contact an administrator.', 'No shop is assigned to your account — contact an administrator.', true)
        === 'Hakuna duka lililopangiwa akaunti yako — wasiliana na msimamizi.',
    'sw, POS core screen, full sentence pair resolves to the Duka translation'
);
setFeatures(false, false);
ok(wLabel('Warehouse required', 'Shop required') === 'Ghala linahitajika', 'sw, pos off -> always Ghala wording, never Duka');

section('Superadmin "Shop Mode" override — forces Shop even when Projects is on');
loadLanguage('en');
setFeatures(true, true); // would normally stay Warehouse outside the POS core screen
setShopModeOverride('1');
ok(isShopLabel(false) === true, 'shop_mode=1 overrides projects-on -> Shop, non-core screen');
ok(isShopLabel(true) === true, 'shop_mode=1 -> still Shop on the POS core screen');
ok(wLabel('Warehouse', 'Shop') === 'Shop', "wLabel() picks up the override off the POS core screen too");
setShopModeOverride('0');
ok(isShopLabel(false) === false, 'shop_mode=0 (explicitly unset) falls back to the automatic pos+projects rule -> Warehouse');
setShopModeOverride(null);
ok(isShopLabel(false) === false, 'shop_mode never set -> same automatic fallback -> Warehouse');
setFeatures(false, false);
setShopModeOverride('1');
ok(isShopLabel(false) === false, 'shop_mode=1 cannot force Shop when POS itself is off for the tenant');
setShopModeOverride(null);

section('wLabelE() HTML-escapes its output');
setFeatures(true, false);
ob_start();
wLabelE('Warehouse & Co', 'Shop & Co');
$out = ob_get_clean();
ok($out === 'Shop &amp; Co', 'wLabelE() escapes the resolved string for markup');

loadLanguage('en');
unset($GLOBALS['__bms_features']);
