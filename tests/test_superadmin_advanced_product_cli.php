<?php
/**
 * tests/test_superadmin_advanced_product_cli.php
 *   php tests/test_superadmin_advanced_product_cli.php
 *
 * "Advanced Product" — the superadmin-only override (products_simple_pos_plan.md
 * §3) that shows the full Add/Edit Product form even on a Simple POS tenant.
 * Same shape, same narrow "opens a tenant's own database" exception, as POS
 * Simple Mode (see tests/test_superadmin_pos_simple_mode_cli.php, which this
 * file's harness is a direct mirror of).
 *
 * Proves, against a real throwaway provisioned tenant:
 *   1. tenantAdvancedProductStatus()/setTenantAdvancedProduct() really read/
 *      write the tenant's own database (system_settings.pos_advanced_product)
 *      AND the control database (tenants.pos_advanced_product_locked) —
 *      verified by direct SQL on both, not just the function's own claim.
 *   2. actions/superadmin_tenant_advanced_product.php's guard chain matches
 *      every other superadmin action.
 *   3. A deleted tenant returns null / refuses cleanly.
 *   4. tenant_view.php's "More" dialog offers the toggle, wires it into the
 *      same 4-way parallel save as Simple Mode/Shop Mode/POS Advanced, and
 *      never embeds the tenant's current value on page load (on-demand only).
 *   5. No tenant-facing UI or endpoint exposes this setting anywhere — it's
 *      superadmin-only by design, same as Simple Mode.
 *   6. advancedProductEnabled() (core/pos_nav.php) reads the same key from
 *      inside the tenant's own request.
 *
 * CLI ONLY. Provisions one real throwaway tenant and removes it afterwards.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Superadmin-action worker ──────────────────────────────────────────────
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

// ─── Superadmin-page worker ────────────────────────────────────────────────
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = parse_url((string)$argv[3], PHP_URL_QUERY) ?: '';
    parse_str($_SERVER['QUERY_STRING'], $_GET);

    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    require_once __DIR__ . '/../core/control_db.php';
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];

    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

// ─── Tenant-side worker ─────────────────────────────────────────────────────
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

echo "\nBMS — Advanced Product override (superadmin side)\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$sub = 'advprod' . bin2hex(random_bytes(3));
$r = provisionTenant('Advanced Product Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $r['ok'] === true, (string)($r['error'] ?? ''));
if (!$r['ok']) { echo "\nCannot continue.\n"; exit(1); }
$tenantId = (int)$r['tenant_id'];
$tenantHost = $sub . '.' . $BASE;

register_shutdown_function(function () use ($tenantId) {
    try {
        $t = getTenant($tenantId);
        if ($t) deleteTenant($tenantId, $t['company_name']);
    } catch (Throwable $e) { error_log('advanced product test cleanup: ' . $e->getMessage()); }
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
    $st = $c->prepare("SELECT pos_advanced_product_locked FROM tenants WHERE id = ?");
    $st->execute([$tenantId]);
    return (int)$st->fetchColumn();
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. tenantAdvancedProductStatus()/setTenantAdvancedProduct() — real cross-DB read/write');

ok('fresh tenant has no pos_advanced_product row yet', tenantSetting($tPdo, 'pos_advanced_product') === null);
ok('fresh tenant is not locked', controlLockFlag($c, $tenantId) === 0);

$status0 = tenantAdvancedProductStatus($tenantId);
ok('status() returns an array for a real tenant', is_array($status0));
ok('status() reports enabled=false by default', $status0 !== null && $status0['enabled'] === false);
ok('status() reports locked=false by default', $status0 !== null && $status0['locked'] === false);

$set1 = setTenantAdvancedProduct($tenantId, true, true);
ok('setTenantAdvancedProduct(true, true) reports ok', $set1['ok'] === true, (string)($set1['error'] ?? ''));
ok('...and the TENANT\'s own database now has pos_advanced_product=1 (direct SQL)', tenantSetting($tPdo, 'pos_advanced_product') === '1');
ok('...and the CONTROL database now has pos_advanced_product_locked=1 (direct SQL)', controlLockFlag($c, $tenantId) === 1);

$status1 = tenantAdvancedProductStatus($tenantId);
ok('status() reflects enabled=true after the write', $status1 !== null && $status1['enabled'] === true);
ok('status() reflects locked=true after the write', $status1 !== null && $status1['locked'] === true);

$set2 = setTenantAdvancedProduct($tenantId, false, false);
ok('setTenantAdvancedProduct(false, false) reports ok', $set2['ok'] === true, (string)($set2['error'] ?? ''));
ok('...and the tenant database is back to pos_advanced_product=0', tenantSetting($tPdo, 'pos_advanced_product') === '0');
ok('...and the control database is back to pos_advanced_product_locked=0', controlLockFlag($c, $tenantId) === 0);

// ─────────────────────────────────────────────────────────────────────────────
section('2. actions/superadmin_tenant_advanced_product.php — guards, with a positive control');

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['auth' => true]);
ok('POSITIVE CONTROL: an authenticated operator CAN read status', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['tenant_id' => $tenantId, 'action' => 'status']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['tenant_id' => $tenantId, 'action' => 'status', '_csrf' => 'wrong-token'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['host' => $tenantHost, 'auth' => true]);
ok('refused from the TENANT\'s own host even when authenticated', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['action' => 'status'], ['auth' => true]);
ok('no tenant_id at all is refused, not treated as "all tenants"', refused($r, 'No tenant specified'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['tenant_id' => $tenantId, 'action' => 'bogus-action'], ['auth' => true]);
ok('an unknown action is refused, not silently ignored', refused($r, 'Unknown action'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['tenant_id' => $tenantId, 'action' => 'set', 'enabled' => 1, 'locked' => 0], ['auth' => true]);
ok('POSITIVE CONTROL: action=set actually persists', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
ok('...verified by direct SQL on the tenant\'s own database', tenantSetting($tPdo, 'pos_advanced_product') === '1');
setTenantAdvancedProduct($tenantId, false, false); // reset for the sections below

// ─────────────────────────────────────────────────────────────────────────────
section('3. A deleted tenant has no database left to query');

$deadSub = 'advproddead' . bin2hex(random_bytes(3));
$rd = provisionTenant('Advanced Product Dead Co', $deadSub, "owner@$deadSub.test", 'Password!123');
ok('throwaway (to-be-deleted) tenant provisioned', $rd['ok'] === true, (string)($rd['error'] ?? ''));
$deadId = (int)$rd['tenant_id'];
deleteTenant($deadId, 'Advanced Product Dead Co');

ok('tenantAdvancedProductStatus() returns null for a deleted tenant', tenantAdvancedProductStatus($deadId) === null);

$r = endpoint('actions/superadmin_tenant_advanced_product.php', ['tenant_id' => $deadId, 'action' => 'status'], ['auth' => true]);
ok('the endpoint refuses cleanly for a deleted tenant too (no crash)', refused($r), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('4. tenant_view.php — the "More" dialog offers the toggle, on-demand only');

$html = route(SA_HOST, '/tenants/view?id=' . $tenantId);
ok('renders with no PHP fatal', !str_contains($html, 'Fatal error'));
ok('the Advanced Product checkbox is present in the More dialog', str_contains($html, 'saAdvancedProductEnabled'));
ok('the page never embeds this tenant\'s current pos_advanced_product value on load (on-demand only)',
    !str_contains($html, 'saAdvancedProductEnabled" checked'));
ok('the status fetch for Advanced Product is wired into the same parallel $.when() as Simple Mode/Shop Mode',
    str_contains($html, "url: '/actions/superadmin_tenant_advanced_product.php'"));
ok('the save wires Advanced Product with locked=1 (superadmin-only, same discipline as Simple Mode)',
    str_contains($html, 'enabled: result.value.advancedProduct'));

// ─────────────────────────────────────────────────────────────────────────────
section('5. Superadmin-only by design — no tenant-facing UI/endpoint exposes this setting');

$avail = tenantRoute($tenantHost, '/available_modules', $ownerUserId);
ok('Available Modules renders with no PHP fatal', !str_contains($avail, 'Fatal error'));
ok('Available Modules never mentions pos_advanced_product at all', !str_contains($avail, 'pos_advanced_product'));

$posSettings = tenantRoute($tenantHost, '/pos_config_settings', $ownerUserId);
ok('POS Settings renders with no PHP fatal', !str_contains($posSettings, 'Fatal error'));
ok('POS Settings never mentions pos_advanced_product at all', !str_contains($posSettings, 'pos_advanced_product'));

// ─────────────────────────────────────────────────────────────────────────────
section('6. advancedProductEnabled() reads the tenant-side setting correctly');

setTenantAdvancedProduct($tenantId, true, true);
$flagOn = tenantRoute($tenantHost, '/dashboard', $ownerUserId); // any tenant page boots core/pos_nav.php
ok('tenant page still renders with no PHP fatal once Advanced Product is on', !str_contains($flagOn, 'Fatal error'));

$directCheck = tenantSetting($tPdo, 'pos_advanced_product');
ok('the raw setting driving advancedProductEnabled() reads back as \'1\'', $directCheck === '1');

setTenantAdvancedProduct($tenantId, false, true); // leave the throwaway tenant in a clean, locked-off state before deletion

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
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --tenant-route ' . escapeshellarg($host) . ' ' . escapeshellarg($uri) . ' ' . escapeshellarg((string)$userId);
    $out = []; exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
}

echo "\nPasses: $pass   Failures: $fail\n";
if ($fail > 0) exit(1);
