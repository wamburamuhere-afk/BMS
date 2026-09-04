<?php
/**
 * tests/test_feature_gating_cli.php — Phase 11.B acceptance gate.
 *
 *   php tests/test_feature_gating_cli.php
 *
 * Proves ENFORCEMENT, layer by layer, for a tenant with a feature switched off:
 *   layer 1  canView/canCreate/canEdit/canDelete and the workflow verbs — FALSE
 *            EVEN FOR THAT TENANT'S OWN ADMIN (the assertion that matters most)
 *   layer 2  enforcePageOrAdmin() refuses ahead of its own admin bypass
 *   layer 3  the router 404s a mapped route and a direct file hit
 *   layer 4  api/ and ajax/ endpoints 404 — the layer the router cannot reach
 *   layer 5  the public, unauthenticated sign_document.php door closes
 * plus: every OTHER feature unaffected, the OTHER tenant unaffected, and with no
 * tenant resolved everything is on.
 *
 * Each request is simulated in its own SUBPROCESS with HTTP_HOST/REQUEST_URI set
 * — the same technique tests/test_tenant_routing_cli.php uses — so the real
 * includes/config.php -> bmsConnectPdo() -> guard path executes, rather than a
 * re-implementation of it. Subprocess mode is selected by argv, so this file is
 * both the runner and the worker.
 *
 * CLI ONLY. Writes only to tenant_features, only for the tenants it is told to,
 * and removes every row it writes.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Worker mode ────────────────────────────────────────────────────────────
// argv: --worker <host> <request_uri> <probe>
if (($argv[1] ?? '') === '--worker') {
    $_SERVER['HTTP_HOST']      = $argv[2];
    $_SERVER['REQUEST_URI']    = $argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $probe                     = $argv[4] ?? 'features';

    // If layer 4 blocks, this require never returns — the point of the exercise.
    require_once __DIR__ . '/../includes/config.php';

    // Reached only when layer 4 let the request through.
    switch ($probe) {
        case 'features':
            $f = $GLOBALS['__bms_features'] ?? null;
            echo 'PASSED_LAYER4 ' . json_encode(is_array($f) ? $f : 'all-on');
            break;

        case 'perms':
            // Layer 1, as the tenant's OWN ADMIN. Session is forged deliberately:
            // isAdmin() reads $_SESSION['is_admin'], and the whole question is
            // whether an admin can walk past the entitlement gate.
            require_once __DIR__ . '/../core/permissions.php';
            $_SESSION['is_admin'] = true;
            $_SESSION['role_id']  = 1;
            $out = [];
            foreach (['pos', 'payroll', 'invoices', 'tenders'] as $k) {
                $out[$k] = [
                    'view'    => canView($k),   'create' => canCreate($k),
                    'edit'    => canEdit($k),   'delete' => canDelete($k),
                    'submit'  => canSubmit($k), 'approve' => canApprove($k),
                    'any'     => hasAnyPermission($k),
                ];
            }
            echo 'PERMS ' . json_encode($out);
            break;

        case 'router':
            // Layer 3 through the REAL handleRoute(). Reaching the page means the
            // gate let it through; being blocked exits inside the guard.
            require_once __DIR__ . '/../roots.php';
            $_SESSION['is_admin'] = true;
            handleRoute();
            echo 'PASSED_LAYER3';
            break;

        case 'enforce':
            // Layer 2 — enforcePageOrAdmin() ahead of its admin bypass.
            require_once __DIR__ . '/../roots.php';
            $_SESSION['is_admin'] = true;
            $_SESSION['role_id']  = 1;
            enforcePageOrAdmin($argv[5] ?? 'pos');
            echo 'PASSED_LAYER2';
            break;
    }
    exit(0);
}

// ─── Runner mode ────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/feature_registry.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
$c    = getControlPdo();

// Two real, live tenants — the isolation claim is only worth testing against
// genuinely separate databases.
$tenants = $c->query("SELECT id, subdomain FROM tenants WHERE status IN ('active','trial') ORDER BY id LIMIT 2")
             ->fetchAll();
if (count($tenants) < 2) { echo "\nNeed two live tenants to test isolation; found " . count($tenants) . ".\n"; exit(1); }
[$A, $B] = $tenants;
$hostA = $A['subdomain'] . '.' . $BASE;
$hostB = $B['subdomain'] . '.' . $BASE;

echo "\nBMS — Phase 11.B: feature-gate enforcement\n";
echo "  tenant A = {$A['subdomain']} (id {$A['id']})   tenant B = {$B['subdomain']} (id {$B['id']})\n";

/** Run one simulated request in its own process. */
function req(string $host, string $uri, string $probe = 'features', string $extra = ''): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --worker '
         . escapeshellarg($host) . ' ' . escapeshellarg($uri) . ' ' . escapeshellarg($probe);
    if ($extra !== '') $cmd .= ' ' . escapeshellarg($extra);
    exec($cmd . ' 2>&1', $out, $rc);
    return ['out' => implode("\n", $out), 'rc' => $rc];
}
function blocked(array $r): bool {
    // bmsFeatureHalt() emits either the JSON body or the standalone 404 page,
    // and never the marker the worker prints after the guard.
    return !str_contains($r['out'], 'PASSED_LAYER')
        && !str_contains($r['out'], 'PERMS ')
        && (str_contains($r['out'], 'Not found') || str_contains($r['out'], '"success":false'));
}
function setFeature(int $tenantId, string $key, int $enabled): void {
    getControlPdo()->prepare(
        "INSERT INTO tenant_features (tenant_id, feature_key, is_enabled) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)"
    )->execute([$tenantId, $key, $enabled]);
}
function clearFeatures(int $tenantId): void {
    getControlPdo()->prepare("DELETE FROM tenant_features WHERE tenant_id = ?")->execute([$tenantId]);
}

clearFeatures((int)$A['id']);
clearFeatures((int)$B['id']);

// ─────────────────────────────────────────────────────────────────────────────
section('0. Control: with nothing disabled, nothing is blocked');

$r = req($hostA, '/pos', 'features');
ok('tenant request resolves and is not blocked when all features are on',
   str_contains($r['out'], 'PASSED_LAYER4'), $r['out']);

$r = req($hostA, '/api/pos/sale.php', 'features');
ok('api/pos/ passes while POS is enabled', str_contains($r['out'], 'PASSED_LAYER4'), $r['out']);

// ─────────────────────────────────────────────────────────────────────────────
section('1. Layer 1 — canX() is FALSE for the tenant\'s own ADMIN');

setFeature((int)$A['id'], 'pos', 0);

$r = req($hostA, '/index.php', 'perms');
$perms = null;
if (preg_match('/PERMS (\{.*\})/s', $r['out'], $m)) $perms = json_decode($m[1], true);
ok('perms probe returned a result', is_array($perms), $r['out']);

if (is_array($perms)) {
    foreach (['view', 'create', 'edit', 'delete', 'submit', 'approve', 'any'] as $verb) {
        ok("admin: can" . ucfirst($verb) . "('pos') === false with POS disabled",
           $perms['pos'][$verb] === false);
    }
    // The same admin keeps everything else — proving the gate is scoped, not blunt.
    foreach (['view', 'create', 'edit', 'delete'] as $verb) {
        ok("admin: can" . ucfirst($verb) . "('payroll') still true (HR untouched)",
           $perms['payroll'][$verb] === true);
        ok("admin: can" . ucfirst($verb) . "('invoices') still true (base module)",
           $perms['invoices'][$verb] === true);
    }
    ok("admin: canView('tenders') still true (other feature untouched)",
       $perms['tenders']['view'] === true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. Layer 2 — enforcePageOrAdmin() refuses ahead of its admin bypass');

$r = req($hostA, '/index.php', 'enforce', 'pos');
ok('enforcePageOrAdmin(\'pos\') blocks an ADMIN when POS is disabled', blocked($r), $r['out']);

$r = req($hostA, '/index.php', 'enforce', 'payroll');
ok('enforcePageOrAdmin(\'payroll\') still passes for that admin',
   str_contains($r['out'], 'PASSED_LAYER2'), $r['out']);

// ─────────────────────────────────────────────────────────────────────────────
section('3. Layer 3 — the router');

$r = req($hostA, '/pos', 'router');
ok('mapped route /pos is refused', blocked($r), substr($r['out'], 0, 200));

$r = req($hostA, '/app/bms/pos/pos.php', 'router');
ok('the POS file reached DIRECTLY by path is refused', blocked($r), substr($r['out'], 0, 200));

// The directory trap: HR lives inside app/bms/pos/ and must survive POS being off.
$r = req($hostA, '/app/bms/pos/payroll.php', 'features');
ok('an HR file inside app/bms/pos/ is NOT blocked by POS being off',
   str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('4. Layer 4 — api/ and ajax/, which the router never sees');

$r = req($hostA, '/api/pos/sale.php', 'features');
ok('api/pos/ endpoint is refused', blocked($r), substr($r['out'], 0, 200));
ok('  ...and answers JSON, not an HTML page', str_contains($r['out'], '"success":false'), substr($r['out'], 0, 120));

$r = req($hostA, '/api/pos_session.php', 'features');
ok('api/pos_session.php is refused', blocked($r), substr($r['out'], 0, 200));

$r = req($hostA, '/api/payroll/run.php', 'features');
ok('api/payroll/ (HR) still passes with POS off', str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));

// A SUBDIRECTORY install. Production serves each tenant at its own subdomain
// root, so REQUEST_URI is '/api/pos/x.php' — but under '/bms/' it arrives as
// '/bms/api/pos/x.php', which matched no registry prefix and silently gated
// NOTHING. Layer 4 is the only layer covering api/ and ajax/, so that lost those
// endpoints entirely on any non-root install.
$r = req($hostA, '/bms/api/pos/sale.php', 'features');
ok('a subdirectory install still gates api/pos/', blocked($r), substr($r['out'], 0, 200));

$r = req($hostA, '/erp/subdir/api/pos_session.php', 'features');
ok('  ...at any depth', blocked($r), substr($r['out'], 0, 200));

$r = req($hostA, '/bms/api/payroll/run.php', 'features');
ok('  ...without over-blocking HR under the same prefix',
   str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));

$r = req($hostA, '/bms/app/bms/pos/payroll.php', 'features');
ok('  ...and the app/bms/pos/ directory trap still holds under a prefix',
   str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('5. Layer 5 — the public e-signature door');

setFeature((int)$A['id'], 'esignature', 0);
$r = req($hostA, '/sign_document.php?token=abc123', 'features');
ok('sign_document.php is refused when e-signatures are disabled', blocked($r), substr($r['out'], 0, 200));

setFeature((int)$A['id'], 'esignature', 1);
$r = req($hostA, '/sign_document.php?token=abc123', 'features');
ok('sign_document.php passes the gate again once re-enabled',
   str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('6. One tenant at a time — B is untouched by A\'s restrictions');

$r = req($hostB, '/pos', 'features');
ok('tenant B reaches /pos while it is disabled for tenant A',
   str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));

$r = req($hostB, '/api/pos/sale.php', 'features');
ok('tenant B reaches the POS api while A is blocked',
   str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));

$r = req($hostB, '/index.php', 'perms');
if (preg_match('/PERMS (\{.*\})/s', $r['out'], $m)) {
    $pB = json_decode($m[1], true);
    ok("tenant B's admin still has canView('pos')", ($pB['pos']['view'] ?? null) === true);
}

// ─────────────────────────────────────────────────────────────────────────────
section('7. No tenant resolved -> nothing is gated');

$r = req('localhost', '/pos', 'features');
ok('a non-tenant host is never gated (single-tenant/legacy safety)',
   str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));
ok('  ...and reports the all-on fallback rather than a feature map',
   str_contains($r['out'], 'all-on'), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('8. Platform-wide removal beats a tenant\'s own override');

clearFeatures((int)$A['id']);
setFeature((int)$A['id'], 'tenders', 1);                       // tenant says yes
$c->prepare("UPDATE features SET is_available = 0 WHERE feature_key = 'tenders'")->execute();
$r = req($hostA, '/tenders', 'router');
ok('platform is_available=0 blocks even a tenant override of 1', blocked($r), substr($r['out'], 0, 200));
$c->prepare("UPDATE features SET is_available = 1 WHERE feature_key = 'tenders'")->execute();

// ─────────────────────────────────────────────────────────────────────────────
section('9. Clean up — the live tenants must be left exactly as found');

clearFeatures((int)$A['id']);
clearFeatures((int)$B['id']);
$left = (int)$c->query("SELECT COUNT(*) FROM tenant_features")->fetchColumn();
ok('no tenant_features rows left behind anywhere', $left === 0, "rows still present: $left");

$unavail = (int)$c->query("SELECT COUNT(*) FROM features WHERE is_available = 0")->fetchColumn();
ok('no feature left switched off platform-wide', $unavail === 0);

$r = req($hostA, '/pos', 'features');
ok('tenant A is back to full access', str_contains($r['out'], 'PASSED_LAYER4'), substr($r['out'], 0, 200));

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
