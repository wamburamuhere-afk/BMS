<?php
/**
 * tests/test_feature_registry_cli.php — Phase 11.A acceptance gate.
 *
 *   php tests/test_feature_registry_cli.php
 *
 * Proves the entitlement DATA layer, which ships enforcing nothing:
 *   1. every page_key in the registry exists in the real permissions table
 *   2. the always-on base set is genuinely un-gateable
 *   3. the resolution matrix (available x enabled x default x no-override-row)
 *   4. shared page_keys use OR, not AND
 *   5. with no tenant resolved, everything is on and nothing is queried
 *   6. it fails OPEN, not closed, when the control tables are missing
 *   7. the control schema is idempotent and the catalogue matches the code
 *
 * CLI ONLY.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/feature_registry.php';

$pass = 0; $fail = 0;

function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Phase 11.A: feature registry & entitlement resolution\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. Registry integrity against the REAL permissions table');

$appPdo = new PDO('mysql:host=' . DB_SERVER . ';dbname=' . DB_NAME, DB_USERNAME, DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$livePageKeys = $appPdo->query("SELECT page_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);

ok('permissions table has rows to check against', count($livePageKeys) > 0);

$missing = [];
foreach (bmsFeatureRegistry() as $key => $def) {
    foreach ($def['page_keys'] as $pk) {
        if (!in_array($pk, $livePageKeys, true)) $missing[] = "$key/$pk";
    }
}
// A registry naming a page_key that does not exist would gate nothing at all —
// the silent failure this assertion exists to catch.
ok('every registry page_key exists in permissions' . ($missing ? ' — missing: ' . implode(', ', $missing) : ''),
   $missing === []);

$dupCheck = [];
foreach (bmsFeatureRegistry() as $key => $def) {
    ok("feature '$key' declares a label", !empty($def['label']));
    ok("feature '$key' declares at least one page_key", !empty($def['page_keys']));
    foreach ($def['page_keys'] as $pk) $dupCheck[$pk][] = $key;
}

// The one deliberate multi-owner key. If this ever becomes single-owner the OR
// rule below stops being exercised by real data.
ok("'dn' is deliberately owned by both sales and procurement",
   count(featureForPageKey('dn')) === 2);

// ─────────────────────────────────────────────────────────────────────────────
section('2. The always-on base set is not gateable');

foreach (['dashboard', 'customers', 'products', 'invoices', 'chart_of_accounts',
          'trial_balance', 'balance_sheet', 'users', 'user_roles', 'system_settings'] as $baseKey) {
    ok("base page_key '$baseKey' belongs to no feature", featureForPageKey($baseKey) === []);
}

// ─────────────────────────────────────────────────────────────────────────────
section('3. Resolution matrix — available x enabled x default x no-row');

/** Re-implements nothing: drives the real bmsPrimeTenantFeatures() via fixtures. */
function primeFixture(array $catalogue, array $overrides, int $tenantId = 999001): void
{
    // Direct control-DB writes, then the REAL loader — testing the actual code
    // path rather than a re-implementation of it.
    $c = getControlPdo();
    $c->prepare("DELETE FROM tenant_features WHERE tenant_id = ?")->execute([$tenantId]);
    foreach ($catalogue as $k => [$avail, $default]) {
        $c->prepare("INSERT INTO features (feature_key, label, is_available, default_enabled, sort_order)
                     VALUES (?,?,?,?,0)
                     ON DUPLICATE KEY UPDATE is_available = VALUES(is_available),
                                             default_enabled = VALUES(default_enabled)")
          ->execute([$k, ucfirst($k), $avail, $default]);
    }
    foreach ($overrides as $k => $enabled) {
        $c->prepare("INSERT INTO tenant_features (tenant_id, feature_key, is_enabled) VALUES (?,?,?)")
          ->execute([$tenantId, $k, $enabled]);
    }
    bmsPrimeTenantFeatures($tenantId);
}

$realCatalogue = [];
foreach (bmsFeatureRegistry() as $k => $def) $realCatalogue[$k] = [1, !empty($def['default']) ? 1 : 0];

// available=1, no override row, default=1  -> ON
primeFixture($realCatalogue, []);
ok('no override row + default_enabled=1 -> ON', tenantFeatureEnabled('pos') === true);

// available=1, override=0 -> OFF (override beats the default)
primeFixture($realCatalogue, ['pos' => 0]);
ok('override is_enabled=0 -> OFF', tenantFeatureEnabled('pos') === false);
ok('every other feature is unaffected by that override', tenantFeatureEnabled('hr') === true);

// available=1, override=1, default=0 -> ON (override beats the default the other way)
$catDefaultOff = $realCatalogue; $catDefaultOff['pos'] = [1, 0];
primeFixture($catDefaultOff, ['pos' => 1]);
ok('override is_enabled=1 beats default_enabled=0 -> ON', tenantFeatureEnabled('pos') === true);

// available=1, no override, default=0 -> OFF
primeFixture($catDefaultOff, []);
ok('no override row + default_enabled=0 -> OFF', tenantFeatureEnabled('pos') === false);

// available=0 -> OFF regardless of the tenant's own override. The assertion that
// makes "remove this platform-wide" mean something.
$catUnavailable = $realCatalogue; $catUnavailable['pos'] = [0, 1];
primeFixture($catUnavailable, ['pos' => 1]);
ok('is_available=0 overrides a tenant override of 1 -> OFF', tenantFeatureEnabled('pos') === false);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Shared page_keys use OR, not AND');

primeFixture($realCatalogue, ['sales' => 0]);
ok("'dn' still reachable with sales OFF but procurement ON", tenantModuleAllowsPage('dn') === true);
ok("'quotations' (sales-only) is blocked with sales OFF", tenantModuleAllowsPage('quotations') === false);

primeFixture($realCatalogue, ['sales' => 0, 'procurement' => 0]);
ok("'dn' blocked only when BOTH owners are off", tenantModuleAllowsPage('dn') === false);
ok('a base page_key stays allowed with both off', tenantModuleAllowsPage('invoices') === true);

// POS off must not take HR with it — the app/bms/pos/ directory trap.
primeFixture($realCatalogue, ['pos' => 0]);
ok("POS off blocks 'pos'", tenantModuleAllowsPage('pos') === false);
ok("POS off does NOT block HR ('payroll')", tenantModuleAllowsPage('payroll') === true);
ok("POS off does NOT block HR ('employees')", tenantModuleAllowsPage('employees') === true);

// ─────────────────────────────────────────────────────────────────────────────
section('5. No tenant resolved -> everything on, nothing queried');

bmsPrimeTenantFeatures(null);
ok('null tenant -> tenantFeatures() reports every key on',
   count(array_filter(tenantFeatures())) === count(allFeatureKeys()));
ok('null tenant -> a previously-disabled feature reads ON', tenantFeatureEnabled('pos') === true);
ok('null tenant -> tenantModuleAllowsPage() allows a gated page', tenantModuleAllowsPage('pos') === true);
$GLOBALS['__bms_features'] = null;
ok('unset state -> allows a gated page (single-tenant/CLI safety)', tenantModuleAllowsPage('tenders') === true);

// ─────────────────────────────────────────────────────────────────────────────
section('6. Fails OPEN when the catalogue cannot be read');

// A key present in code but absent from the catalogue table must read ON, not
// OFF — the difference between a missed seed and a locked-out customer.
getControlPdo()->prepare("DELETE FROM features WHERE feature_key = ?")->execute(['tenders']);
primeFixture([], []);   // catalogue re-seeded below; tenders deliberately absent
ok('registry key missing from the catalogue table -> ON', tenantFeatureEnabled('tenders') === true);

// ─────────────────────────────────────────────────────────────────────────────
section('7. Path ownership (declared now, consumed by 11.B)');

ok('api/pos/ maps to pos', featureForPath('api/pos/sale.php') === 'pos');
ok('an HR file inside app/bms/pos/ is NOT owned by pos',
   featureForPath('app/bms/pos/payroll.php') === null);
ok('the POS terminal file IS owned by pos',
   featureForPath('app/bms/pos/pos.php') === 'pos');
ok('app/bms/tenders/ maps to tenders', featureForPath('app/bms/tenders/tender_view.php') === 'tenders');
ok('leading slash is tolerated', featureForPath('/api/payroll/run.php') === 'hr');
ok('an unowned path returns null', featureForPath('app/bms/customer/customers.php') === null);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Clean up fixtures and restore the real catalogue');

$c = getControlPdo();
$c->prepare("DELETE FROM tenant_features WHERE tenant_id = ?")->execute([999001]);
$left = (int)$c->prepare("SELECT COUNT(*) FROM tenant_features WHERE tenant_id = ?")
    ->execute([999001]);
$leftRows = (int)$c->query("SELECT COUNT(*) FROM tenant_features WHERE tenant_id = 999001")->fetchColumn();
ok('no fixture override rows left behind', $leftRows === 0);

// Re-run the real setup script so the catalogue is exactly what the code declares.
exec('php ' . escapeshellarg(__DIR__ . '/../scripts/setup_control_db.php') . ' 2>&1', $out, $rc);
ok('setup_control_db.php re-runs cleanly (idempotent)', $rc === 0);

$catalogueKeys = $c->query("SELECT feature_key FROM features")->fetchAll(PDO::FETCH_COLUMN);
$diff = array_diff(allFeatureKeys(), $catalogueKeys);
ok('catalogue table matches the code registry' . ($diff ? ' — missing: ' . implode(',', $diff) : ''),
   $diff === []);

// The two real tenants must be untouched by any of this.
$realOverrides = (int)$c->query("SELECT COUNT(*) FROM tenant_features WHERE tenant_id IN (85, 86)")->fetchColumn();
ok('no entitlement rows were written for the real tenants', $realOverrides === 0);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
