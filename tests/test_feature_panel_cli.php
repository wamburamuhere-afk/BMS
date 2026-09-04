<?php
/**
 * tests/test_feature_panel_cli.php — Phase 11.C acceptance gate.
 *
 *   php tests/test_feature_panel_cli.php
 *
 * Proves the superadmin's control surface:
 *   1. tenantFeatureMatrix() reports the effective state AND why
 *   2. saving overrides works, is scoped to one tenant, and takes effect on that
 *      tenant's very next request
 *   3. a request equal to the platform default deletes the override row rather
 *      than pinning the tenant to a value that can never follow a default change
 *   4. platform is_available=0 beats a tenant's own grant
 *   5. every change lands in tenant_admin_log with actor, tenant and direction —
 *      and an unchanged Save writes no log noise
 *   6. the endpoints refuse without a superadmin session, on the wrong host,
 *      on GET, and without CSRF
 *   7. base modules are not in the catalogue at all, so they cannot be revoked
 *
 * CLI ONLY. Cleans up every row it writes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Endpoint worker ────────────────────────────────────────────────────────
// argv: --endpoint <relative-file> <json-post> <method> <host> <auth:0|1>
// Runs the REAL action file in its own process with $_SERVER/$_POST set, rather
// than through `php -r`, whose quoting broke silently on Windows and made every
// refusal assertion pass vacuously.
if (($argv[1] ?? '') === '--endpoint') {
    $file   = (string)$argv[2];
    // base64: Windows escapeshellarg() eats the double quotes out of JSON, which
    // silently delivered an EMPTY $_POST and made the positive control fail.
    $post   = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $method = (string)($argv[4] ?? 'POST');
    $host   = (string)($argv[5] ?? 'localhost');
    $auth   = (string)($argv[6] ?? '0') === '1';

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = '/' . $file;

    if ($auth) {
        // A genuinely authenticated operator: real session, real CSRF token.
        // This is the positive control that keeps the refusals below meaningful.
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

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/feature_registry.php';
require_once __DIR__ . '/../core/tenant_admin.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';

$tenants = $c->query("SELECT id, subdomain FROM tenants WHERE status IN ('active','trial') ORDER BY id LIMIT 2")->fetchAll();
if (count($tenants) < 2) { echo "\nNeed two live tenants; found " . count($tenants) . ".\n"; exit(1); }
[$A, $B] = $tenants;

echo "\nBMS — Phase 11.C: superadmin feature-control panel\n";
echo "  tenant A = {$A['subdomain']} (id {$A['id']})   tenant B = {$B['subdomain']} (id {$B['id']})\n";

// A superadmin identity, so setTenantFeatures() can attribute its audit rows the
// way the real panel does.
$sa = $c->query("SELECT id, email FROM superadmins ORDER BY id LIMIT 1")->fetch();
if ($sa) { $_SESSION['superadmin_id'] = (int)$sa['id']; }

$c->exec("DELETE FROM tenant_features");
$c->exec("UPDATE features SET is_available = 1");

/** One simulated tenant request, in its own process (see 11.B's suite). */
function req(string $host, string $uri): string {
    $worker = __DIR__ . '/test_feature_gating_cli.php';
    $cmd = 'php ' . escapeshellarg($worker) . ' --worker ' . escapeshellarg($host)
         . ' ' . escapeshellarg($uri) . ' features';
    exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}
function logCount(PDO $c, string $action): int {
    return (int)$c->query("SELECT COUNT(*) FROM tenant_admin_log WHERE action = " . $c->quote($action))->fetchColumn();
}

// The panel answers ONLY on superadmin.<base> — assertSuperadminHost() gives a
// flat 404 to every other host, including localhost and the marketing root.
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$hostA = $A['subdomain'] . '.' . $BASE;
$hostB = $B['subdomain'] . '.' . $BASE;

// ─────────────────────────────────────────────────────────────────────────────
section('1. The matrix reports state AND the reason for it');

$m = tenantFeatureMatrix((int)$A['id']);
ok('matrix returns every catalogue feature', count($m) === count(allFeatureKeys()), count($m) . ' rows');

$byKey = [];
foreach ($m as $row) $byKey[$row['key']] = $row;
ok('a default-on feature with no override reads effective', $byKey['pos']['effective'] === true);
ok('  ...and explains itself as "On by default"', $byKey['pos']['reason'] === 'On by default');
ok('override is null when nothing was decided for this tenant', $byKey['pos']['override'] === null);

// ─────────────────────────────────────────────────────────────────────────────
section('2. Saving an override — scoped, audited, effective next request');

$before = logCount($c, 'update_features');
$desired = [];
foreach (allFeatureKeys() as $k) $desired[$k] = true;
$desired['pos'] = false;                      // revoke exactly one

$r = setTenantFeatures((int)$A['id'], $desired);
ok('setTenantFeatures reports ok', $r['ok'] === true, (string)($r['error'] ?? ''));
ok('exactly one module counted as changed', $r['changed'] === 1, 'changed=' . $r['changed']);

$m2 = [];
foreach (tenantFeatureMatrix((int)$A['id']) as $row) $m2[$row['key']] = $row;
ok('POS now reads not-effective', $m2['pos']['effective'] === false);
ok('  ...and explains itself as "Denied to this tenant"', $m2['pos']['reason'] === 'Denied to this tenant');
ok('HR is untouched', $m2['hr']['effective'] === true);

ok('the change is visible on tenant A\'s next real request',
   str_contains(req($hostA, '/index.php'), '"pos":false'), req($hostA, '/index.php'));
ok('tenant B is completely unaffected',
   str_contains(req($hostB, '/index.php'), '"pos":true'), req($hostB, '/index.php'));

ok('one audit row was written', logCount($c, 'update_features') === $before + 1);
$last = $c->query("SELECT * FROM tenant_admin_log WHERE action='update_features' ORDER BY id DESC LIMIT 1")->fetch();
ok('  ...naming the tenant', (int)$last['tenant_id'] === (int)$A['id']);
ok('  ...naming the direction and the module', str_contains((string)$last['detail'], 'disabled: pos'), (string)$last['detail']);
ok('  ...and attributing an actor', !empty($last['actor_email']) || $sa === false);

// ─────────────────────────────────────────────────────────────────────────────
section('3. A value equal to the default deletes the row, never pins it');

$rows = (int)$c->query("SELECT COUNT(*) FROM tenant_features WHERE tenant_id = {$A['id']}")->fetchColumn();
ok('only the one genuine override is stored, not ten rows', $rows === 1, "rows=$rows");

$desired['pos'] = true;                       // back to the platform default
setTenantFeatures((int)$A['id'], $desired);
$rows = (int)$c->query("SELECT COUNT(*) FROM tenant_features WHERE tenant_id = {$A['id']}")->fetchColumn();
ok('returning a module to the default removes its override row entirely', $rows === 0, "rows=$rows");

$m3 = [];
foreach (tenantFeatureMatrix((int)$A['id']) as $row) $m3[$row['key']] = $row;
ok('and it follows the default again', $m3['pos']['reason'] === 'On by default');

// A tenant left at the default must FOLLOW a later change to that default —
// the whole reason redundant rows are deleted rather than written.
setPlatformFeature('pos', null, false);
$m4 = [];
foreach (tenantFeatureMatrix((int)$A['id']) as $row) $m4[$row['key']] = $row;
ok('changing the platform default moves a defaulted tenant with it', $m4['pos']['effective'] === false);
setPlatformFeature('pos', null, true);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Platform removal beats a tenant\'s own grant');

setTenantFeatures((int)$A['id'], ['tenders' => true]);
setPlatformFeature('tenders', false, null);

$m5 = [];
foreach (tenantFeatureMatrix((int)$A['id']) as $row) $m5[$row['key']] = $row;
ok('a granted module is not effective once removed platform-wide', $m5['tenders']['effective'] === false);
ok('  ...and says so rather than blaming the tenant', $m5['tenders']['reason'] === 'Removed platform-wide');
ok('the tenant\'s live request agrees', str_contains(req($hostA, '/index.php'), '"tenders":false'));

$pf = [];
foreach (platformFeatures() as $row) $pf[$row['feature_key']] = $row;
ok('platformFeatures() reports 0 tenants using a removed module', (int)$pf['tenders']['tenants_using'] === 0);
ok('platformFeatures() counts live tenants for an available module',
   (int)$pf['hr']['tenants_using'] === (int)$pf['hr']['tenants_live'],
   $pf['hr']['tenants_using'] . ' of ' . $pf['hr']['tenants_live']);

$plBefore = logCount($c, 'platform_feature');
setPlatformFeature('tenders', true, null);
ok('restoring is audited too', logCount($c, 'platform_feature') === $plBefore + 1);

$plBefore = logCount($c, 'platform_feature');
setPlatformFeature('tenders', true, null);      // already true
ok('a no-op platform change writes no log noise', logCount($c, 'platform_feature') === $plBefore);

// ─────────────────────────────────────────────────────────────────────────────
section('5. An unchanged Save writes no audit noise');

$before = logCount($c, 'update_features');
$all = [];
foreach (allFeatureKeys() as $k) $all[$k] = true;
$r = setTenantFeatures((int)$A['id'], $all);
ok('saving with nothing changed reports 0 changes', $r['changed'] === 0);
ok('  ...and writes no audit row', logCount($c, 'update_features') === $before);

// ─────────────────────────────────────────────────────────────────────────────
section('6. The endpoints refuse everything they should');

function endpoint(string $file, array $post, array $server = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint '
         . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($post))) . ' '
         . escapeshellarg($server['method'] ?? 'POST') . ' '
         . escapeshellarg($server['host'] ?? SA_HOST) . ' '
         . escapeshellarg(!empty($server['auth']) ? '1' : '0');
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);
    // A crashed worker must never read as a refusal — that is exactly how the
    // first version of this suite passed four assertions while testing nothing.
    if (str_contains($joined, 'Parse error') || str_contains($joined, 'Fatal error')) {
        $joined = 'WORKER_CRASHED: ' . $joined;
    }
    return ['out' => $joined, 'rc' => $rc];
}

/** A refusal is only a refusal if the worker actually ran and said no. */
function refused(array $r, string $expect = ''): bool {
    if (str_contains($r['out'], 'WORKER_CRASHED')) return false;
    if (str_contains($r['out'], '"success":true')) return false;
    return $expect === '' ? true : str_contains($r['out'], $expect);
}

// POSITIVE CONTROL FIRST. Every refusal below would also pass against an
// endpoint that simply never worked, so prove the happy path works through the
// exact same harness before asserting that anything is refused.
$r = endpoint('actions/superadmin_tenant_features.php',
              ['tenant_id' => (int)$A['id'], 'features' => ['pos' => 0]],
              ['auth' => true]);
ok('POSITIVE CONTROL: an authenticated operator CAN save through the endpoint',
   str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
ok('  ...and it really took effect on the tenant next request',
   str_contains(req($hostA, '/index.php'), '"pos":false'));
setTenantFeatures((int)$A['id'], ['pos' => true]);   // undo the control

$r = endpoint('actions/superadmin_tenant_features.php', ['tenant_id' => (int)$A['id'], 'features' => ['pos' => 0]]);
ok('per-tenant endpoint refuses without a superadmin session',
   refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_features.php', ['feature_key' => 'pos', 'is_available' => 0]);
ok('platform endpoint refuses without a superadmin session',
   refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_features.php', [], ['method' => 'GET', 'auth' => true]);
ok('per-tenant endpoint refuses GET even when authenticated',
   refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_features.php',
              ['tenant_id' => (int)$A['id'], 'features' => ['pos' => 0], '_csrf' => 'wrong-token'],
              ['auth' => true]);
ok('per-tenant endpoint refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

// The tenant-host refusal is the property that makes these flags unreachable by
// the companies they govern. Authenticated on purpose: even a real operator's
// session must not reach the panel through a tenant's hostname.
$r = endpoint('actions/superadmin_tenant_features.php',
              ['tenant_id' => (int)$A['id'], 'features' => ['pos' => 0]],
              ['host' => $hostA, 'auth' => true]);
ok('per-tenant endpoint is refused from a TENANT host even when authenticated',
   refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_platform_features.php',
              ['feature_key' => 'pos', 'is_available' => 0],
              ['host' => $hostA, 'auth' => true]);
ok('platform endpoint is refused from a TENANT host too', refused($r), substr($r['out'], 0, 200));

$stillOn = str_contains(req($hostA, '/index.php'), '"pos":true');
ok('  ...and none of those refused calls changed anything', $stillOn);

// ─────────────────────────────────────────────────────────────────────────────
section('7. Base modules cannot be revoked because they are not switchable');

$catalogue = $c->query("SELECT feature_key FROM features")->fetchAll(PDO::FETCH_COLUMN);
foreach (['dashboard', 'customers', 'invoices', 'users', 'user_roles', 'chart_of_accounts'] as $baseKey) {
    ok("'$baseKey' is absent from the catalogue, so no switch exists for it",
       !in_array($baseKey, $catalogue, true));
}

$r = setTenantFeatures((int)$A['id'], ['invoices' => false]);   // not a feature key
ok('asking to revoke a non-feature is ignored, not invented', $r['changed'] === 0);
ok('  ...and invoices stays reachable', tenantModuleAllowsPage('invoices') === true);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Clean up');

$c->exec("DELETE FROM tenant_features");
$c->exec("UPDATE features SET is_available = 1");
exec('php ' . escapeshellarg(__DIR__ . '/../scripts/setup_control_db.php') . ' 2>&1', $o, $rc);
ok('control DB setup still idempotent afterwards', $rc === 0);
ok('no override rows left behind',
   (int)$c->query("SELECT COUNT(*) FROM tenant_features")->fetchColumn() === 0);
ok('no feature left removed platform-wide',
   (int)$c->query("SELECT COUNT(*) FROM features WHERE is_available = 0")->fetchColumn() === 0);
ok('both live tenants are back to full access',
   str_contains(req($hostA, '/index.php'), '"pos":true') && str_contains(req($hostB, '/index.php'), '"pos":true'));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
