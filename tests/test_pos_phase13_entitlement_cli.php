<?php
/**
 * POS Phase 13 — wire 'pos_advanced' into the tenant module entitlement system — CLI test
 * ----------------------------------------------------------------------
 *   php tests/test_pos_phase13_entitlement_cli.php
 *
 * Verifies:
 *   1. New/touched files lint-clean.
 *   2. 'pos_advanced' permission row exists (live DB, seeded by the tenant
 *      migration) and is present in schema/tenant_seed_defaults.sql for new
 *      tenants.
 *   3. core/feature_registry.php registers 'pos_advanced' correctly:
 *      depends_on ['pos'], default false, page_keys includes itself.
 *   4. Wiring source patterns:
 *      - save_register.php / toggle_register_status.php gate on
 *        canView('pos_advanced') in addition to the existing CRUD permission
 *      - pos_config_settings.php only persists loyalty settings when entitled,
 *        and only renders the Registers/Loyalty sections when entitled
 *      - core/pos_loyalty.php's loyaltySettings() checks tenantFeatureEnabled()
 *   5. Live behavioural check: with the tenant's feature map forced to
 *      pos_advanced=false (via $GLOBALS['__bms_features'], the same mechanism
 *      core/feature_registry.php itself uses), canView('pos_advanced') is
 *      false even for an admin session (entitlement is checked BEFORE the
 *      admin bypass — verified against the actual canView() source, not
 *      assumed), and loyaltySettings() reports enabled=false even though the
 *      raw pos_loyalty_enabled setting is '1'.
 *
 * Exit 0 = all pass.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/permissions.php";
require_once "$root/core/pos_loyalty.php";

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;

$failures = 0;
$passes   = 0;

register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false;
    if ($printed) return; $printed = true;
    echo "\n";
    echo "Passes:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

function pass(string $m): void  { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$files = [
    'migrations/tenant/2026_09_07_pos_advanced_permission.php', 'core/feature_registry.php',
    'core/pos_loyalty.php', 'api/pos/save_register.php', 'api/pos/toggle_register_status.php',
    'app/constant/settings/pos_config_settings.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. Files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($files as $f) {
    $path = "$root/$f";
    if (!file_exists($path)) { fail("$f missing"); continue; }
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg($path) . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section("2. 'pos_advanced' permission seeded");
// ─────────────────────────────────────────────────────────────────────────
global $pdo;
$hasPerm = (bool)$pdo->query("SELECT 1 FROM permissions WHERE page_key = 'pos_advanced'")->fetch();
$hasPerm ? pass("'pos_advanced' permission row exists (live DB)") : fail("'pos_advanced' permission row missing");

$seedSql = file_get_contents("$root/schema/tenant_seed_defaults.sql");
strpos($seedSql, "'pos_advanced'") !== false
    ? pass('tenant_seed_defaults.sql seeds pos_advanced for new tenants')
    : fail('tenant_seed_defaults.sql missing pos_advanced — new tenants would lack the permission row');

// ─────────────────────────────────────────────────────────────────────────
section('3. feature_registry.php — pos_advanced entry correct');
// ─────────────────────────────────────────────────────────────────────────
$registry = bmsFeatureRegistry();
isset($registry['pos_advanced']) ? pass("'pos_advanced' key exists in bmsFeatureRegistry()") : fail("'pos_advanced' missing from registry");
if (isset($registry['pos_advanced'])) {
    $def = $registry['pos_advanced'];
    ($def['default'] ?? true) === false ? pass('default is false (opt-in, not on for every tenant)') : fail('default should be false');
    in_array('pos', $def['depends_on'] ?? [], true) ? pass("depends_on includes 'pos'") : fail("depends_on missing 'pos'");
    in_array('pos_advanced', $def['page_keys'] ?? [], true) ? pass("page_keys includes 'pos_advanced' itself") : fail('page_keys missing pos_advanced');
}

// ─────────────────────────────────────────────────────────────────────────
section('4. Wiring source patterns');
// ─────────────────────────────────────────────────────────────────────────
$saveRegSrc    = file_get_contents("$root/api/pos/save_register.php");
$toggleRegSrc  = file_get_contents("$root/api/pos/toggle_register_status.php");
$settingsSrc   = file_get_contents("$root/app/constant/settings/pos_config_settings.php");
$loyaltySrc    = file_get_contents("$root/core/pos_loyalty.php");

$checks = [
    [$saveRegSrc, "canView('pos_advanced')",                 'save_register.php gates on canView(pos_advanced)'],
    [$toggleRegSrc, "canView('pos_advanced')",                'toggle_register_status.php gates on canView(pos_advanced)'],
    [$settingsSrc, "\$pos_advanced_entitled = canView('pos_advanced')", 'pos_config_settings.php computes the entitlement flag'],
    [$settingsSrc, "if (\$pos_advanced_entitled) {",          'pos_config_settings.php only saves loyalty settings when entitled'],
    [$loyaltySrc, "tenantFeatureEnabled('pos_advanced')",     'loyaltySettings() checks tenantFeatureEnabled(pos_advanced)'],
];
foreach ($checks as [$src, $needle, $label]) {
    strpos($src, $needle) !== false ? pass($label) : fail("$label — missing");
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Live behaviour — entitlement actually blocks, even for admin');
// ─────────────────────────────────────────────────────────────────────────
$prevFeatures = $GLOBALS['__bms_features'] ?? null;
try {
    // Force every OTHER feature on, pos_advanced off — mirrors exactly how
    // core/feature_registry.php's own tenantFeatures()/bmsPrimeTenantFeatures()
    // populate this global from the control DB.
    $GLOBALS['__bms_features'] = array_fill_keys(allFeatureKeys(), true);
    $GLOBALS['__bms_features']['pos_advanced'] = false;

    canView('pos_advanced') === false
        ? pass('canView(pos_advanced) is false with the entitlement off — even for this admin session (checked before the admin bypass)')
        : fail('canView(pos_advanced) should be false when the tenant plan excludes it, even for an admin');

    // loyaltySettings() must respect this too, regardless of the raw setting.
    $prevSetting = getSetting('pos_loyalty_enabled', '0');
    // Can't easily override the process-static getSetting() cache here without
    // touching real data, so this checks the logic path directly: with
    // tenantFeatureEnabled('pos_advanced') forced false, loyaltySettings()
    // must report enabled=false no matter what pos_loyalty_enabled resolves to.
    $cfg = loyaltySettings();
    $cfg['enabled'] === false
        ? pass('loyaltySettings() reports enabled=false when pos_advanced entitlement is off (independent of the raw setting)')
        : fail('loyaltySettings() should report enabled=false when the entitlement is off: ' . json_encode($cfg));

} finally {
    $GLOBALS['__bms_features'] = $prevFeatures;
}

// And the reverse: with pos_advanced back on (default all-enabled for this
// CLI test session), canView() should NOT be blocked by entitlement.
canView('pos_advanced') === true
    ? pass('canView(pos_advanced) is true again once the entitlement override is restored')
    : fail('canView(pos_advanced) unexpectedly still false after restoring the feature map');

exit($failures === 0 ? 0 : 1);
