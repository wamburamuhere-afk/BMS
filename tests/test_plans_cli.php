<?php
/**
 * tests/test_plans_cli.php — Phase C acceptance gate (superadmin
 * professional-gap plan): reusable pricing/feature plans.
 *
 *   php tests/test_plans_cli.php
 *
 * Proves:
 *   1. core/plans.php — CRUD, validation, plan_key uniqueness/collision
 *      handling, and that applyPlanToTenant() is a pure orchestrator: it
 *      reuses setTenantFeatures()/setTenantQuotas() with no separate write path
 *   2. renaming a plan does NOT orphan tenants already on it (plan_key, not
 *      name, is the stable identity — the whole reason it exists)
 *   3. retiring a plan blocks new applications but never touches tenants
 *      already on it
 *   4. actions/superadmin_plans.php and superadmin_apply_plan.php — guard
 *      chain (session/GET/CSRF/tenant-host), with positive controls
 *   5. plans.php and tenant_view.php render through the real router
 *
 * CLI ONLY. Creates throwaway plans and one throwaway tenant; removes both.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Endpoint worker ────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--endpoint') {
    $file   = (string)$argv[2];
    $post   = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $method = (string)($argv[4] ?? 'POST');
    $host   = (string)($argv[5] ?? 'localhost');
    $auth   = (string)($argv[6] ?? '0') === '1';

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = '/' . $file;

    if ($auth) {
        require_once __DIR__ . '/../core/superadmin_auth.php';
        require_once __DIR__ . '/../helpers.php';
        superadminSessionReady();
        require_once __DIR__ . '/../core/control_db.php';
        $row = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($row) $_SESSION['superadmin_id'] = (int)$row['id'];
        if (!array_key_exists('_csrf', $post)) $post['_csrf'] = csrf_token();
    }

    $_POST = $post;
    require __DIR__ . '/../' . $file;
    exit(0);
}

// ─── Router worker ──────────────────────────────────────────────────────────
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = parse_url((string)$argv[3], PHP_URL_QUERY) ?: '';
    parse_str($_SERVER['QUERY_STRING'], $_GET);

    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];

    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

// ─── Runner ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/plans.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function endpoint(string $file, array $post, array $server = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint '
         . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg($server['method'] ?? 'POST') . ' '
         . escapeshellarg($server['host'] ?? SA_HOST) . ' '
         . escapeshellarg(!empty($server['auth']) ? '1' : '0');
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);
    if (str_contains($joined, 'Parse error') || str_contains($joined, 'Fatal error')) $joined = 'WORKER_CRASHED: ' . $joined;
    return ['out' => $joined, 'rc' => $rc];
}
function refused(array $r, string $expect = ''): bool {
    if (str_contains($r['out'], 'WORKER_CRASHED')) return false;
    if (str_contains($r['out'], '"success":true')) return false;
    return $expect === '' ? true : str_contains($r['out'], $expect);
}
function route(string $host, string $uri): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

echo "\nBMS — Phase C: reusable plans\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$createdPlanIds = [];
register_shutdown_function(function () use ($c, &$createdPlanIds) {
    foreach ($createdPlanIds as $id) {
        $c->exec("DELETE FROM plan_features WHERE plan_id = " . (int)$id);
        $c->exec("DELETE FROM plans WHERE id = " . (int)$id);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
section('1. createPlan()/updatePlan() — validation, plan_key, feature set');

$r = createPlan(['name' => '', 'max_users' => null, 'max_storage_mb' => null]);
ok('rejects an empty name', $r['ok'] === false && $r['id'] === null);

$r = createPlan(['name' => 'Test Plan A', 'max_users' => 0]);
ok('rejects a zero user limit (must be >=1 or unlimited)', $r['ok'] === false);

$r = createPlan(['name' => 'Phase C Test Plan', 'description' => 'A test plan', 'max_users' => 5,
    'max_storage_mb' => 200, 'feature_keys' => ['pos', 'hr']]);
ok('a valid create succeeds', $r['ok'] === true, (string)($r['error'] ?? ''));
$planId = (int)$r['id'];
$createdPlanIds[] = $planId;

$p = getPlan($planId);
ok('plan_key auto-derived from name', $p['plan_key'] === 'phase-c-test-plan', (string)$p['plan_key']);
ok('feature_keys stored exactly', count(array_diff(planFeatureKeys($planId), ['pos', 'hr'])) === 0 && count(planFeatureKeys($planId)) === 2);

$r2 = createPlan(['name' => 'Phase C Test Plan', 'max_users' => 10]);   // same name again
ok('a colliding name gets a distinct plan_key (not rejected)', $r2['ok'] === true);
$createdPlanIds[] = (int)$r2['id'];
$p2 = getPlan((int)$r2['id']);
ok('  ...and the key really differs', $p2['plan_key'] !== $p['plan_key'], $p2['plan_key']);

$ur = updatePlan($planId, ['name' => 'Phase C Test Plan RENAMED', 'max_users' => 8,
    'max_storage_mb' => 300, 'feature_keys' => ['esignature']]);
ok('update succeeds', $ur['ok'] === true, (string)($ur['error'] ?? ''));
$pAfter = getPlan($planId);
ok('name changed', $pAfter['name'] === 'Phase C Test Plan RENAMED');
ok('plan_key UNCHANGED by a rename', $pAfter['plan_key'] === $p['plan_key']);
ok('feature set fully replaced (pos/hr gone, esignature present)',
   planFeatureKeys($planId) === ['esignature']);

// ─────────────────────────────────────────────────────────────────────────────
section('2. applyPlanToTenant() — pure orchestration, real tenant, real effect');

$sub = 'planstest' . bin2hex(random_bytes(3));
$pr = provisionTenant('Plans Test Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $pr['ok'] === true, (string)($pr['error'] ?? ''));
$tenantId = (int)$pr['tenant_id'];
register_shutdown_function(function () use ($tenantId) {
    try { $t = getTenant($tenantId); if ($t) deleteTenant($tenantId, $t['company_name']); }
    catch (Throwable $e) { error_log('plans test cleanup: ' . $e->getMessage()); }
});

$ar = applyPlanToTenant($tenantId, $planId);
ok('apply succeeds', $ar['ok'] === true, (string)($ar['error'] ?? ''));

$t = getTenant($tenantId);
ok('tenants.plan set to the PLAN KEY, not the display name', $t['plan'] === $pAfter['plan_key'], (string)$t['plan']);
ok('max_users applied (8)', (int)$t['max_users'] === 8);
ok('max_storage_mb applied (300)', (int)$t['max_storage_mb'] === 300);

$matrix = tenantFeatureMatrix($tenantId);
$byKey = [];
foreach ($matrix as $f) $byKey[$f['key']] = $f['effective'];
ok('esignature (in the plan) is effective', $byKey['esignature'] === true);
ok('pos (NOT in the plan after rename) is now off', $byKey['pos'] === false);

$logRow = $c->prepare("SELECT action, detail FROM tenant_admin_log WHERE tenant_id = ? AND action = 'apply_plan' ORDER BY id DESC LIMIT 1");
$logRow->execute([$tenantId]);
ok('the apply is durably logged to tenant_admin_log', (bool)$logRow->fetch());

section('2b. Renaming the plan again does NOT orphan the tenant already on it');
updatePlan($planId, ['name' => 'Yet Another Name', 'max_users' => 8, 'max_storage_mb' => 300, 'feature_keys' => ['esignature']]);
$t2 = getTenant($tenantId);
ok('tenants.plan (the key) is untouched by the rename', $t2['plan'] === $pAfter['plan_key']);
$resolved = getPlanByKey($t2['plan']);
ok('...and it still resolves to a real plan, with the NEW display name', $resolved && $resolved['name'] === 'Yet Another Name');

// ─────────────────────────────────────────────────────────────────────────────
section('3. Retiring a plan blocks new applications, never touches existing tenants');

setPlanActive($planId, false);
$ar2 = applyPlanToTenant($tenantId, $planId);
ok('applying a RETIRED plan is refused', $ar2['ok'] === false);
$t3 = getTenant($tenantId);
ok('the tenant already on it is completely unaffected', (int)$t3['max_users'] === 8 && (int)$t3['max_storage_mb'] === 300);

setPlanActive($planId, true);
$ar3 = applyPlanToTenant($tenantId, $planId);
ok('restoring the plan makes it applicable again', $ar3['ok'] === true);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Endpoint guards — positive controls, then every refusal');

$r = endpoint('actions/superadmin_plans.php',
    ['action' => 'create', 'name' => 'Endpoint Test Plan', 'max_users' => '3'], ['auth' => true]);
ok('POSITIVE CONTROL: create via endpoint works', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
$epPlan = getPlanByKey('endpoint-test-plan');
ok('  ...and it really persisted', $epPlan !== null);
if ($epPlan) $createdPlanIds[] = (int)$epPlan['id'];

$r = endpoint('actions/superadmin_plans.php', ['action' => 'create', 'name' => 'X']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_plans.php', ['action' => 'create', 'name' => 'X'], ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_plans.php', ['action' => 'create', 'name' => 'X', '_csrf' => 'bad'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_plans.php', ['action' => 'create', 'name' => 'X'],
    ['host' => $sub . '.' . $BASE, 'auth' => true]);
ok('refused from a TENANT host even when authenticated', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_apply_plan.php', ['tenant_id' => $tenantId, 'plan_id' => $planId], ['auth' => true]);
ok('POSITIVE CONTROL: apply-plan endpoint works', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_apply_plan.php', ['tenant_id' => $tenantId, 'plan_id' => $planId]);
ok('apply-plan refuses without a session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_apply_plan.php', ['tenant_id' => $tenantId], ['auth' => true]);
ok('apply-plan requires plan_id', refused($r), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('5. Routing — /plans and /tenants/view render through the real router');

ok('route map has a plans entry', isset(superadminRouteMap()['plans']));
$html = route(SA_HOST, '/plans');
ok('plans.php renders with no PHP fatal', !str_contains($html, 'Fatal error'));
ok('Plans nav item marked active', str_contains($html, 'nav-link active') && str_contains($html, '>Plans<'));
ok('the test plan appears on the page', str_contains($html, 'Yet Another Name'));

$html2 = route(SA_HOST, '/tenants/view?id=' . $tenantId);
ok('tenant_view.php renders with no PHP fatal', !str_contains($html2, 'Fatal error'));
ok('shows the Plan card with the resolved current plan name', str_contains($html2, 'Yet Another Name'));
ok('has the Apply plan control', str_contains($html2, 'id="btnApplyPlan"'));

echo "\n---\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
