<?php
/**
 * tests/test_superadmin_pos_simple_mode_cli.php
 *   php tests/test_superadmin_pos_simple_mode_cli.php
 *
 * POS Simple Mode, reachable from the superadmin Tenant Detail page (Modules
 * > Point of Sale > More) — the THIRD deliberate, narrow exception to "the
 * superadmin panel never opens a tenant's own database" (see
 * core/tenant_admin.php::tenantPosSimpleModeStatus()/setTenantPosSimpleMode()).
 *
 * Proves, against a real throwaway provisioned tenant:
 *   1. tenantPosSimpleModeStatus()/setTenantPosSimpleMode() really read/write
 *      the tenant's own database (system_settings.pos_simple_mode) AND the
 *      control database (tenants.pos_simple_mode_locked) — verified by
 *      direct SQL against BOTH databases, not just the function's own claim.
 *   2. actions/superadmin_tenant_pos_simple_mode.php's guard chain matches
 *      every other superadmin action: session, POST-only, CSRF, tenant-host
 *      refusal — with a positive control proving the harness itself works.
 *   3. A deleted tenant returns null / refuses cleanly (no database left).
 *   4. tenant_view.php shows the "More" button only for a non-deleted
 *      tenant, and never embeds the tenant's current Simple Mode value on
 *      page load itself (on-demand only, same discipline as the Users card).
 *   5. SUPERADMIN-ONLY, PERMANENTLY: the tenant's own admin has no UI and no
 *      endpoint that can reach this setting at all any more (the earlier
 *      "More" button/endpoint on Available Modules/POS Settings was removed
 *      outright, not merely gated) — proven from inside the TENANT's own
 *      subdomain/session, and proven even with the lock column explicitly
 *      set to 0, so this isn't "currently locked", it's "nothing left to
 *      unlock". tenant_view.php's own dialog always submits locked=1.
 *
 * CLI ONLY. Provisions one real throwaway tenant and removes it afterwards.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Superadmin-action worker — runs a real action file as an authenticated
//     superadmin, same technique as test_tenant_user_directory_cli.php ───────
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

// ─── Superadmin-page worker — renders tenant_view.php through handleRoute() ─
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

// ─── Tenant-side worker — a real request into the TENANT's own subdomain,
//     resolving $pdo to that tenant's own database via roots.php's normal
//     host-based resolution (core/tenant_resolver.php explicitly documents
//     this as the supported way a CLI test drives a specific tenant), with a
//     real admin session in THAT tenant — not the superadmin panel at all ──
if (($argv[1] ?? '') === '--tenant-route') {
    $host = (string)$argv[2];
    $uri  = (string)$argv[3];
    $userId = (int)$argv[4];

    $_SERVER['HTTP_HOST']      = $host;
    $_SERVER['REQUEST_URI']    = $uri;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = parse_url($uri, PHP_URL_QUERY) ?: '';
    parse_str($_SERVER['QUERY_STRING'], $_GET);

    require_once __DIR__ . '/../roots.php';
    $_SESSION['user_id']  = $userId;
    $_SESSION['role_id']  = 1;
    $_SESSION['is_admin'] = true;

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
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — POS Simple Mode (superadmin side)\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$sub = 'possimple' . bin2hex(random_bytes(3));
$r = provisionTenant('POS Simple Mode Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $r['ok'] === true, (string)($r['error'] ?? ''));
if (!$r['ok']) { echo "\nCannot continue.\n"; exit(1); }
$tenantId = (int)$r['tenant_id'];
$tenantHost = $sub . '.' . $BASE;

register_shutdown_function(function () use ($tenantId) {
    try {
        $t = getTenant($tenantId);
        if ($t) deleteTenant($tenantId, $t['company_name']);
    } catch (Throwable $e) { error_log('pos simple mode test cleanup: ' . $e->getMessage()); }
});

$tRow = $c->prepare("SELECT * FROM tenants WHERE id = ?");
$tRow->execute([$tenantId]);
$tData = $tRow->fetch();
$pw = decryptTenantSecret((string)$tData['db_password_encrypted']);
$tPdo = new PDO("mysql:host={$tData['db_host']};dbname={$tData['db_name']};charset=utf8mb4",
    $tData['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ownerUserId = (int)$tPdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
ok('a real owner user exists in the freshly provisioned tenant', $ownerUserId > 0);

function tenantSetting(PDO $tPdo, string $key) {
    $st = $tPdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
function controlLockFlag(PDO $c, int $tenantId): int {
    $st = $c->prepare("SELECT pos_simple_mode_locked FROM tenants WHERE id = ?");
    $st->execute([$tenantId]);
    return (int)$st->fetchColumn();
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. tenantPosSimpleModeStatus()/setTenantPosSimpleMode() — real cross-DB read/write');

ok('fresh tenant has no pos_simple_mode row yet', tenantSetting($tPdo, 'pos_simple_mode') === null);
ok('fresh tenant is not locked', controlLockFlag($c, $tenantId) === 0);

$status0 = tenantPosSimpleModeStatus($tenantId);
ok('status() returns an array for a real tenant', is_array($status0));
ok('status() reports enabled=false by default', $status0 !== null && $status0['enabled'] === false);
ok('status() reports locked=false by default', $status0 !== null && $status0['locked'] === false);

$set1 = setTenantPosSimpleMode($tenantId, true, true);
ok('setTenantPosSimpleMode(true, true) reports ok', $set1['ok'] === true, (string)($set1['error'] ?? ''));
ok('...and the TENANT\'s own database now has pos_simple_mode=1 (direct SQL)', tenantSetting($tPdo, 'pos_simple_mode') === '1');
ok('...and the CONTROL database now has pos_simple_mode_locked=1 (direct SQL)', controlLockFlag($c, $tenantId) === 1);

$status1 = tenantPosSimpleModeStatus($tenantId);
ok('status() reflects enabled=true after the write', $status1 !== null && $status1['enabled'] === true);
ok('status() reflects locked=true after the write', $status1 !== null && $status1['locked'] === true);

$set2 = setTenantPosSimpleMode($tenantId, false, false);
ok('setTenantPosSimpleMode(false, false) reports ok', $set2['ok'] === true, (string)($set2['error'] ?? ''));
ok('...and the tenant database is back to pos_simple_mode=0', tenantSetting($tPdo, 'pos_simple_mode') === '0');
ok('...and the control database is back to pos_simple_mode_locked=0', controlLockFlag($c, $tenantId) === 0);

// ─────────────────────────────────────────────────────────────────────────────
section('2. actions/superadmin_tenant_pos_simple_mode.php — guards, with a positive control');

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['auth' => true]);
ok('POSITIVE CONTROL: an authenticated operator CAN read status', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['tenant_id' => $tenantId, 'action' => 'status']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['tenant_id' => $tenantId, 'action' => 'status', '_csrf' => 'wrong-token'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['host' => $tenantHost, 'auth' => true]);
ok('refused from the TENANT\'s own host even when authenticated', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['action' => 'status'], ['auth' => true]);
ok('no tenant_id at all is refused, not treated as "all tenants"', refused($r, 'No tenant specified'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['tenant_id' => $tenantId, 'action' => 'bogus-action'], ['auth' => true]);
ok('an unknown action is refused, not silently ignored', refused($r, 'Unknown action'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['tenant_id' => $tenantId, 'action' => 'set', 'enabled' => 1, 'locked' => 0], ['auth' => true]);
ok('POSITIVE CONTROL: action=set actually persists', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
ok('...verified by direct SQL on the tenant\'s own database', tenantSetting($tPdo, 'pos_simple_mode') === '1');
setTenantPosSimpleMode($tenantId, false, false); // reset for the sections below

// ─────────────────────────────────────────────────────────────────────────────
section('3. A deleted tenant has no database left to query');

$deadSub = 'possimpledead' . bin2hex(random_bytes(3));
$rd = provisionTenant('POS Simple Dead Co', $deadSub, "owner@$deadSub.test", 'Password!123');
ok('throwaway (to-be-deleted) tenant provisioned', $rd['ok'] === true, (string)($rd['error'] ?? ''));
$deadId = (int)$rd['tenant_id'];
deleteTenant($deadId, 'POS Simple Dead Co');

ok('tenantPosSimpleModeStatus() returns null for a deleted tenant', tenantPosSimpleModeStatus($deadId) === null);

$r = endpoint('actions/superadmin_tenant_pos_simple_mode.php', ['tenant_id' => $deadId, 'action' => 'status'], ['auth' => true]);
ok('the endpoint refuses cleanly for a deleted tenant too (no crash)', refused($r), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('4. tenant_view.php — the "More" button, on-demand only');

$html = route(SA_HOST, '/tenants/view?id=' . $tenantId);
ok('renders with no PHP fatal', !str_contains($html, 'Fatal error'));
ok('the "More" button for POS is present', str_contains($html, 'openPosMoreModal()'));
ok('the page never embeds this tenant\'s current pos_simple_mode value on load (on-demand only)',
    !str_contains($html, 'saPosSimpleEnabled" checked'));

// POS Advanced / Restaurant POS moved OUT of the main Modules grid (no
// longer separate top-level rows — "will not be professional to separate
// it", grouped inside Point of Sale > More instead) and INTO the modal's
// JS constants, which — unlike Simple Mode — need no on-demand fetch since
// they're plain control-DB reads already available at render time.
ok('POS Advanced is no longer a standalone row in the main Modules grid', !str_contains($html, 'id="f_pos_advanced"'));
ok('Restaurant POS is no longer a standalone row in the main Modules grid', !str_contains($html, 'id="f_restaurant_pos"'));
ok('POS_ADVANCED JS constant is present for the More dialog', str_contains($html, 'const POS_ADVANCED = {'));
ok('RESTAURANT_POS JS constant is present for the More dialog', str_contains($html, 'const RESTAURANT_POS = {'));
ok('the More dialog\'s JS wires POS Advanced into the same superadmin_tenant_features.php the main grid uses',
    str_contains($html, 'pos_advanced: result.value.posAdvanced'));
ok('the More dialog\'s JS wires Restaurant POS into the same superadmin_tenant_features.php the main grid uses',
    str_contains($html, 'restaurant_pos: result.value.restaurantPos'));

$htmlDeleted = route(SA_HOST, '/tenants/view?id=' . $deadId);
ok('a deleted tenant\'s page renders with no PHP fatal either', !str_contains($htmlDeleted, 'Fatal error'));

// ─────────────────────────────────────────────────────────────────────────────
section('5. Superadmin-only, permanently — no tenant path exists at all, regardless of the lock value');

// The tenant-facing "More" button/modal/endpoint this feature briefly had
// (tenants.pos_simple_mode_locked=0 meaning "tenant may self-manage") has
// been removed entirely — the tenant's own admin has NO UI and NO endpoint
// that can reach this setting any more, full stop. Proven from inside the
// TENANT's own subdomain/session (not just by reading source), and proven
// even with locked explicitly set to 0 — so this isn't "currently locked",
// it's "there is nothing left to unlock".
$unlockedResult = setTenantPosSimpleMode($tenantId, true, false); // enabled=1, locked=0
ok('setTenantPosSimpleMode(locked=false) still succeeds (the column/plumbing still exists)', $unlockedResult['ok'] === true);

ok('the tenant-facing save endpoint file does not exist on disk at all', !is_file(dirname(__DIR__) . '/api/pos/save_simple_mode.php'));

$avail = tenantRoute($tenantHost, '/available_modules', $ownerUserId);
ok('Available Modules renders with no PHP fatal', !str_contains($avail, 'Fatal error'));
ok('Available Modules has no Simple Mode modal/button, even with locked=0', !str_contains($avail, 'posSimpleModeModal'));
ok('Available Modules never mentions pos_simple_mode at all', !str_contains($avail, 'pos_simple_mode'));

$posSettings = tenantRoute($tenantHost, '/pos_config_settings', $ownerUserId);
ok('POS Settings renders with no PHP fatal', !str_contains($posSettings, 'Fatal error'));
ok('POS Settings has no Simple Mode checkbox, even with locked=0', !str_contains($posSettings, 'id="pos_simple_mode"'));
ok('POS Settings never mentions pos_simple_mode at all', !str_contains($posSettings, 'pos_simple_mode'));

// tenant_view.php's own "More" dialog no longer offers a lock toggle either
// — it always submits locked=1, since there is nothing left for a tenant to
// be trusted with.
$saveResult = endpoint('actions/superadmin_tenant_pos_simple_mode.php',
    ['tenant_id' => $tenantId, 'action' => 'set', 'enabled' => 1, 'locked' => 1], ['auth' => true]);
ok('superadmin can still set enabled/locked via the action endpoint directly', str_contains($saveResult['out'], '"success":true'), substr($saveResult['out'], 0, 200));
ok('...verified by direct SQL on the tenant\'s own database', tenantSetting($tPdo, 'pos_simple_mode') === '1');

setTenantPosSimpleMode($tenantId, false, true); // leave the throwaway tenant in a clean, locked state before deletion

// ─────────────────────────────────────────────────────────────────────────────
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
function tenantRoute(string $host, string $uri, int $userId): string {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --tenant-route '
         . escapeshellarg($host) . ' ' . escapeshellarg($uri) . ' ' . escapeshellarg((string)$userId);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

echo "\n---\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
