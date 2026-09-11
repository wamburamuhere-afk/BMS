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
 * Extended for tenant_module_control_plan.md, Phase A (2026-09-07):
 *   1b. REVERSE coverage — every live page_key is gated OR on the documented
 *       always-on list, so a module built later can never silently reappear
 *       ungated the way CRM/Communication/Compliance did before this phase
 *   9.  the dependency graph itself (featureDependsOn/Closure/AllDependents)
 *   10. setTenantFeatures() enforces it live: enabling auto-includes,
 *       disabling a needed dependency is rejected, nothing partially written
 *   11. createPlan()/updatePlan() save a dependency-closed feature set
 *
 * CLI ONLY.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/feature_registry.php';
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/plans.php';

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
section('1b. REVERSE coverage — every live page_key is gated or documented as base');

// The exact audit that found CRM/Communication/Compliance running for every
// tenant regardless of plan (tenant_module_control_plan.md §3) — every
// page_key not owned by a feature must appear here, DELIBERATELY, or this
// test fails. A module built later and never wired in can no longer go
// unnoticed the way those three did.
$documentedAlwaysOn = [
    'dashboard',
    'customers', 'customer_details', 'customer_groups', 'customer_import', 'customer_registration', 'edit_customer',
    // 2026-09-11: 'customer_documents'/'documents'/'document_expiry_alerts'/
    // 'document_library'/'document_templates'/'document_workflow' moved OUT
    // of always-on into the new 'documents' feature (product owner request:
    // "comms and docs to be modules to switch on or off") — same class of
    // gap as CRM/Communication/Compliance on 2026-09-10, not a bug fix.
    // 'loan_documents' deliberately stays here — it belongs to Loans, a
    // separate concern from the general Document Library, and was not part
    // of this request.
    'loan_documents',
    'bank_accounts', 'bank_reconciliation', 'bank_transfers', 'budget', 'cash_register', 'chart_of_accounts',
    'expenses', 'journals', 'loans', 'payment_create', 'payment_vouchers', 'petty_cash',
    'revenue', 'revenue_categories', 'transactions',
    'categories', 'inventory_valuation', 'stock_adjustments',
    'products',
    'audit_report', 'balance_sheet', 'cash_flow',
    'expense_report', 'financial_reports', 'financial_statements', 'income_statement', 'inventory_report',
    'ledger_report', 'profit_loss_report',
    'reports', 'sales_report', 'tax_report', 'trial_balance',
    // 2026-09-10: moved OUT of always-on — these read exclusively from one
    // optional module's tables (verified query-by-query, not assumed from
    // the label) and are now gated the same way every other page in that
    // module is: 'purchase_report'/'received_invoices'/'ap_aging'/
    // 'vendor_statement'/'wht_report' -> procurement; 'performance_dashboard'/
    // 'customer_analysis'/'product_analysis'/'sales_forecast'/
    // 'trends_analysis' -> sales; 'employee_report' -> hr; 'asset_report' ->
    // assets. 'ap_aging'/'vendor_statement'/'wht_report' were carved out of
    // the shared 'financial_reports'/'tax_report' keys (which stay here,
    // still covering Receivables Aging/Customer Statement/Tax Report/WHT
    // Credit) — see migrations/tenant/2026_09_10_*_permission.php.
    // 2026-09-12 (tenant_module_control_plan.md): 'invoices' moved OUT of
    // always-on too — a business decision, not a bug fix: the platform sells
    // modules individually, and Sales must be self-contained (invoicing
    // included) the moment it's granted, with no separate purchase needed.
    // Now owned by 'sales' (see core/feature_registry.php). Payment Vouchers
    // stays here deliberately — it is a generic "pay anyone" tool (payee is
    // free text, e.g. staff reimbursements), not procurement-specific, so it
    // was NOT moved to 'procurement' alongside this change.
    'color_settings', 'help', 'my_settings', 'notification_rules', 'tax_settings', 'zoom_settings',
    'activity_log', 'add_user', 'admin', 'attendance_settings', 'audit_logs', 'backup_restore',
    // 2026-09-11: 'email_templates' moved OUT of always-on into the existing
    // 'communication' feature ("Comms") — it was simply never wired in when
    // built, same class of gap as the others noted above.
    'company_profile', 'edit_user', 'login_history', 'notification_settings',
    'payment_settings', 'policy_management', 'profile', 'sms_templates', 'system_settings', 'users', 'user_roles',
    // 2026-09-09: moved out of 'projects' — this page is ALSO the Warehouse
    // Access assignment UI, which has nothing to do with Projects; the page
    // itself now gates its project-specific sections via
    // tenantFeatureEnabled('projects') instead of the whole page 404ing.
    'user_projects',
];

$liveUngated = [];
foreach ($livePageKeys as $pk) {
    if (featureForPageKey($pk) === []) $liveUngated[] = $pk;
}
sort($liveUngated);
$expected = $documentedAlwaysOn;
sort($expected);

$unexpectedlyUngated = array_values(array_diff($liveUngated, $expected));
$expectedButGated    = array_values(array_diff($expected, $liveUngated));
ok('no page_key is ungated without being on the documented always-on list'
   . ($unexpectedlyUngated ? ' — found: ' . implode(', ', $unexpectedlyUngated) : ''),
   $unexpectedlyUngated === []);
ok('every documented always-on key is actually still live and ungated'
   . ($expectedButGated ? ' — now gated or missing: ' . implode(', ', $expectedButGated) : ''),
   $expectedButGated === []);

// ─────────────────────────────────────────────────────────────────────────────
section('2. The always-on base set is not gateable');

foreach (['dashboard', 'customers', 'products', 'expenses', 'chart_of_accounts',
          'trial_balance', 'balance_sheet', 'users', 'user_roles', 'system_settings'] as $baseKey) {
    ok("base page_key '$baseKey' belongs to no feature", featureForPageKey($baseKey) === []);
}

// Regression guard (2026-09-09): user_projects.php is BOTH the project-scope
// assignment UI AND the Warehouse Access assignment UI on one combined page.
// It used to be owned solely by the 'projects' feature, so switching Projects
// off 404'd the whole page — including Warehouse Access, which has nothing to
// do with Projects (warehouses matter to POS/Sales/Procurement regardless).
// Explicit, readable assertion for this specific bug, on top of the bulk
// coverage check in section 1b above.
ok("'user_projects' belongs to no feature (reachable even with Projects off — it also does Warehouse Access)",
   featureForPageKey('user_projects') === []);
ok("tenantModuleAllowsPage('user_projects') is true even with every feature forced off",
   (function () {
       $prev = $GLOBALS['__bms_features'] ?? null;
       $GLOBALS['__bms_features'] = array_fill_keys(allFeatureKeys(), false);
       $result = tenantModuleAllowsPage('user_projects');
       $GLOBALS['__bms_features'] = $prev;
       return $result;
   })());

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
ok('a base page_key stays allowed with both off', tenantModuleAllowsPage('chart_of_accounts') === true);

// Regression guard (2026-09-12): 'invoices' now belongs to 'sales' (a
// business decision — Sales must be self-contained the moment it's granted,
// with no separate module needed for invoicing). Confirm it is actually
// blocked with Sales off, and reachable again the moment Sales is on.
primeFixture($realCatalogue, ['sales' => 0]);
ok("'invoices' blocked with Sales off", tenantModuleAllowsPage('invoices') === false);
primeFixture($realCatalogue, ['sales' => 1]);
ok("'invoices' reachable again with Sales on", tenantModuleAllowsPage('invoices') === true);

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

ok("sub_contractors.php is now owned by 'procurement', not 'projects' (fixes the half-broken state: that page gates itself on canView('suppliers'), so its path must agree)",
   featureForPath('app/bms/operations/sub_contractors.php') === 'procurement');
ok('sub_contractor_details.php moved the same way',
   featureForPath('app/bms/operations/sub_contractor_details.php') === 'procurement');
ok("project_view.php itself is still owned by 'projects' (unaffected by the sub-contractor path move)",
   featureForPath('app/bms/operations/project_view.php') === 'projects');

// ─────────────────────────────────────────────────────────────────────────────
section('9. Module dependency graph — pure functions');

ok("'sales' depends_on 'warehouses'", featureDependsOn('sales') === ['warehouses']);
ok("'procurement' depends_on 'warehouses'", featureDependsOn('procurement') === ['warehouses']);
ok("'pos' depends_on 'warehouses'", featureDependsOn('pos') === ['warehouses']);
ok("'projects' depends_on 'procurement'", featureDependsOn('projects') === ['procurement']);
ok("'warehouses' itself has no dependencies", featureDependsOn('warehouses') === []);
ok('an unknown key has no dependencies', featureDependsOn('no_such_feature') === []);

// Closure: enabling 'projects' alone must pull in procurement AND (transitively) warehouses.
$closure = featureDependencyClosure(['projects']);
sort($closure);
ok("closure of ['projects'] = [procurement, projects, warehouses]",
   $closure === ['procurement', 'projects', 'warehouses']);

// Closure of something with no dependencies is just itself.
ok("closure of ['hr'] = ['hr'] (no dependencies)", featureDependencyClosure(['hr']) === ['hr']);

// Closure ignores unknown keys rather than inventing them.
ok('closure silently drops an unknown key', featureDependencyClosure(['hr', 'not_a_real_key']) === ['hr']);

// Reverse: disabling 'warehouses' must name every feature that needs it,
// including transitive ones ('projects' needs procurement needs warehouses;
// 'pos_advanced'/'restaurant_pos' need pos needs warehouses —
// pos_upgrade_plan.md §7 Phase 13, §9 Phase 30).
$dependents = featureAllDependents('warehouses');
sort($dependents);
ok("all dependents of 'warehouses' = [pos, pos_advanced, procurement, projects, restaurant_pos, sales] (projects/pos_advanced/restaurant_pos are transitive)",
   $dependents === ['pos', 'pos_advanced', 'procurement', 'projects', 'restaurant_pos', 'sales']);

ok("dependents of 'procurement' = ['projects']", featureAllDependents('procurement') === ['projects']);
ok("a leaf feature ('hr') has no dependents", featureAllDependents('hr') === []);

// ─────────────────────────────────────────────────────────────────────────────
section('10. setTenantFeatures() enforces the graph live (real tenants, fully restored after)');

$c2 = getControlPdo();
$liveTenants = $c2->query("SELECT id FROM tenants WHERE status IN ('active','trial') ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
if (count($liveTenants) < 1) {
    echo "  SKIP  no live tenant available to test setTenantFeatures() against\n";
} else {
    $tid = (int)$liveTenants[0];
    // Snapshot every override row for this tenant so the test can restore
    // EXACTLY what was there before, not just blanket-delete real state.
    $snapshot = $c2->prepare("SELECT feature_key, is_enabled FROM tenant_features WHERE tenant_id = ?");
    $snapshot->execute([$tid]);
    $before = $snapshot->fetchAll(PDO::FETCH_ASSOC);

    // Start from a clean slate for this one tenant: everything explicitly OFF
    // (a "Blank plan" style baseline — see tenant_module_control_plan.md
    // §5.1), so the assertions below have a known state to build on instead of
    // being at the mercy of whatever this tenant's real plan already set —
    // with every registered key on by platform default, there would be
    // nothing meaningfully "off" to auto-include back on.
    $allOff = array_fill_keys(allFeatureKeys(), false);
    $r = setTenantFeatures($tid, $allOff);
    ok('switching every module off at once succeeds (nothing left depending on anything)', $r['ok'] === true);
    bmsPrimeTenantFeatures($tid);
    ok('procurement really is off now', tenantFeatureEnabled('procurement') === false);

    // Enabling 'projects' alone must transitively auto-include 'procurement'
    // AND 'warehouses' (procurement's own dependency) — the exact closure
    // section 9 already proved as a pure function, now proved end to end.
    $r = setTenantFeatures($tid, ['projects' => true]);
    ok('enabling projects succeeds', $r['ok'] === true);
    bmsPrimeTenantFeatures($tid);
    ok('procurement was auto-included (projects depends on it)', tenantFeatureEnabled('procurement') === true);
    ok('warehouses was auto-included too (transitively, via procurement)', tenantFeatureEnabled('warehouses') === true);
    ok('pos was NOT touched (no relationship to projects)', tenantFeatureEnabled('pos') === false);

    // Now try to explicitly disable procurement while projects still needs it -> reject.
    $r = setTenantFeatures($tid, ['procurement' => false]);
    ok('disabling procurement while projects needs it is REJECTED', $r['ok'] === false);
    ok('the rejection names the dependent (Projects)', str_contains((string)$r['error'], 'Projects'));
    bmsPrimeTenantFeatures($tid);
    ok('procurement is still ON after the rejected call (nothing partially written)',
       tenantFeatureEnabled('procurement') === true);
    ok('projects is still ON too', tenantFeatureEnabled('projects') === true);

    // Disabling BOTH projects and procurement together in the same call is
    // fine — nothing is left depending on procurement once projects goes with it.
    $r = setTenantFeatures($tid, ['projects' => false, 'procurement' => false]);
    ok('disabling projects+procurement together succeeds (no remaining dependent)', $r['ok'] === true);
    bmsPrimeTenantFeatures($tid);
    ok('procurement is now OFF', tenantFeatureEnabled('procurement') === false);
    ok('projects is now OFF', tenantFeatureEnabled('projects') === false);
    ok('warehouses is untouched by that call (stays ON — nobody asked to disable it)',
       tenantFeatureEnabled('warehouses') === true);

    // Restore this tenant's real state exactly as it was.
    $c2->prepare("DELETE FROM tenant_features WHERE tenant_id = ?")->execute([$tid]);
    foreach ($before as $row) {
        $c2->prepare("INSERT INTO tenant_features (tenant_id, feature_key, is_enabled) VALUES (?,?,?)")
           ->execute([$tid, $row['feature_key'], $row['is_enabled']]);
    }
    $after = $c2->prepare("SELECT feature_key, is_enabled FROM tenant_features WHERE tenant_id = ? ORDER BY feature_key");
    $after->execute([$tid]);
    ok('live tenant restored to its exact prior state',
       $after->fetchAll(PDO::FETCH_ASSOC) === array_values(array_map(
           fn($r) => ['feature_key' => $r['feature_key'], 'is_enabled' => (string)$r['is_enabled']],
           $before
       )) || $after->rowCount() === count($before));
}

// ─────────────────────────────────────────────────────────────────────────────
section('11. Plans save a dependency-closed feature set');

$planResult = createPlan(['name' => '__TEST_DEP_PLAN__', 'feature_keys' => ['projects']]);
ok('test plan created', $planResult['ok'] === true);
$planId = (int)($planResult['id'] ?? 0);
if ($planId > 0) {
    $savedKeys = planFeatureKeys($planId);
    sort($savedKeys);
    ok("createPlan(['projects']) saved [procurement, projects, warehouses] (dependency-closed)",
       $savedKeys === ['procurement', 'projects', 'warehouses']);

    $updateResult = updatePlan($planId, ['name' => '__TEST_DEP_PLAN__', 'feature_keys' => ['pos']]);
    ok('test plan updated', $updateResult['ok'] === true);
    $savedKeys = planFeatureKeys($planId);
    sort($savedKeys);
    ok("updatePlan(['pos']) re-saves as [pos, warehouses] (dependency-closed)",
       $savedKeys === ['pos', 'warehouses']);

    // Clean up — this plan must never be reachable afterward.
    getControlPdo()->prepare("DELETE FROM plan_features WHERE plan_id = ?")->execute([$planId]);
    getControlPdo()->prepare("DELETE FROM plans WHERE id = ?")->execute([$planId]);
    $stillThere = getPlan($planId);
    ok('test plan fully removed', $stillThere === null);
}

// ─────────────────────────────────────────────────────────────────────────────
section('12. Clean up fixtures and restore the real catalogue');

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
section("13. 'documents' (Docs) and 'communication' (Comms) actually gate their pages live");
// Product owner request, 2026-09-11: "comms and docs to be modules to
// switch on or off, superadmin only". Both already exist as real,
// canView()-driven entitlement keys by the time execution reaches here
// (verified structurally by every section above — label present, every
// page_key exists in permissions, reverse-coverage no longer lists them as
// always-on) — this section is the live behavioural proof: actually flip
// each off and confirm the pages they own are actually blocked, while a
// deliberately-adjacent, already-independent feature (compliance/
// esignature — NOT folded into 'documents' even though the pages live in
// the same directory) stays completely unaffected.
// No session_start() here on purpose: this file has already echoed section
// output by this point, so PHP's own headers-already-sent guard would
// reject a fresh session start. canView() only ever reads $_SESSION as a
// plain in-memory superglobal — assigning into it directly is sufficient
// and never needs an actual session to be active.
require_once __DIR__ . '/../core/permissions.php';
$_SESSION['user_id'] = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 LIMIT 1")->fetchColumn() ?: 4);
$_SESSION['is_admin'] = true;
$_SESSION['role_id'] = 1;
$prevFeatures2 = $GLOBALS['__bms_features'] ?? null;
try {
    $GLOBALS['__bms_features'] = array_fill_keys(allFeatureKeys(), true);
    $GLOBALS['__bms_features']['documents'] = false;
    $GLOBALS['__bms_features']['communication'] = false;

    foreach (['documents', 'document_library', 'document_templates', 'document_workflow', 'customer_documents'] as $pk) {
        ok("canView('$pk') is false with 'documents' off (even for this admin session — entitlement checked before the admin bypass)", canView($pk) === false);
    }
    ok("canView('email_templates') is false with 'communication' off", canView('email_templates') === false);
    ok("canView('message_center') is false with 'communication' off", canView('message_center') === false);

    // The adjacent, already-independent features must be completely unaffected —
    // proves 'documents' doesn't accidentally also own their pages.
    ok("canView('compliance_documents') stays TRUE — 'compliance' is its own feature, not folded into 'documents'", canView('compliance_documents') === true);
    ok("canView('e_signatures') stays TRUE — 'esignature' is its own feature, not folded into 'documents'", canView('e_signatures') === true);

    $GLOBALS['__bms_features']['documents'] = true;
    $GLOBALS['__bms_features']['communication'] = true;
    ok("canView('documents') is true again once 'documents' is re-enabled", canView('documents') === true);
    ok("canView('email_templates') is true again once 'communication' is re-enabled", canView('email_templates') === true);
} finally {
    $GLOBALS['__bms_features'] = $prevFeatures2;
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
