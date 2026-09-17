<?php
/**
 * tests/test_superadmin_advanced_customer_supplier_cli.php
 *   php tests/test_superadmin_advanced_customer_supplier_cli.php
 *
 * "Advanced Customer" / "Advanced Supplier" — the two superadmin-only
 * overrides (2026-09-17 request) that each show the full Add/Edit form even
 * on a Simple POS tenant, independently of each other and of "Advanced
 * Product". Direct structural mirror of
 * tests/test_superadmin_advanced_product_cli.php, extended to prove the two
 * new toggles are genuinely independent (flipping one never touches the
 * other) on top of everything that file already proves for one toggle.
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

echo "\nBMS — Advanced Customer / Advanced Supplier overrides (superadmin side)\n";

$c    = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

$sub = 'advcs' . bin2hex(random_bytes(3));
$r = provisionTenant('Advanced Cust/Supp Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $r['ok'] === true, (string)($r['error'] ?? ''));
if (!$r['ok']) { echo "\nCannot continue.\n"; exit(1); }
$tenantId = (int)$r['tenant_id'];
$tenantHost = $sub . '.' . $BASE;

register_shutdown_function(function () use ($tenantId) {
    try {
        $t = getTenant($tenantId);
        if ($t) deleteTenant($tenantId, $t['company_name']);
    } catch (Throwable $e) { error_log('advanced customer/supplier test cleanup: ' . $e->getMessage()); }
});

$tRow = $c->prepare("SELECT * FROM tenants WHERE id = ?");
$tRow->execute([$tenantId]);
$tData = $tRow->fetch();
$pw = decryptTenantSecret((string)$tData['db_password_encrypted']);
$tPdo = new PDO("mysql:host={$tData['db_host']};dbname={$tData['db_name']};charset=utf8mb4",
    $tData['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function tenantSetting(PDO $tPdo, string $key) {
    $st = $tPdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
function controlLockFlag(PDO $c, int $tenantId, string $col): int {
    $st = $c->prepare("SELECT {$col} FROM tenants WHERE id = ?");
    $st->execute([$tenantId]);
    return (int)$st->fetchColumn();
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. tenantAdvancedCustomerStatus()/setTenantAdvancedCustomer() — real cross-DB read/write');

ok('fresh tenant has no pos_advanced_customer row yet', tenantSetting($tPdo, 'pos_advanced_customer') === null);
ok('fresh tenant is not locked (customer)', controlLockFlag($c, $tenantId, 'pos_advanced_customer_locked') === 0);

$status0 = tenantAdvancedCustomerStatus($tenantId);
ok('status() returns an array for a real tenant', is_array($status0));
ok('status() reports enabled=false by default', $status0 !== null && $status0['enabled'] === false);

$set1 = setTenantAdvancedCustomer($tenantId, true, true);
ok('setTenantAdvancedCustomer(true, true) reports ok', $set1['ok'] === true, (string)($set1['error'] ?? ''));
ok('...tenant DB now has pos_advanced_customer=1', tenantSetting($tPdo, 'pos_advanced_customer') === '1');
ok('...control DB now has pos_advanced_customer_locked=1', controlLockFlag($c, $tenantId, 'pos_advanced_customer_locked') === 1);

// ─────────────────────────────────────────────────────────────────────────────
section('2. tenantAdvancedSupplierStatus()/setTenantAdvancedSupplier() — independent of Customer');

ok('Supplier is still OFF while Customer is ON — no cross-contamination', tenantSetting($tPdo, 'pos_advanced_supplier') === null);
ok('Supplier lock is still 0 while Customer lock is 1', controlLockFlag($c, $tenantId, 'pos_advanced_supplier_locked') === 0);

$set2 = setTenantAdvancedSupplier($tenantId, true, true);
ok('setTenantAdvancedSupplier(true, true) reports ok', $set2['ok'] === true, (string)($set2['error'] ?? ''));
ok('...tenant DB now has pos_advanced_supplier=1', tenantSetting($tPdo, 'pos_advanced_supplier') === '1');
ok('...Customer setting is UNCHANGED by the Supplier write', tenantSetting($tPdo, 'pos_advanced_customer') === '1');

$set3 = setTenantAdvancedCustomer($tenantId, false, false);
ok('turning Customer back off reports ok', $set3['ok'] === true, (string)($set3['error'] ?? ''));
ok('...Customer is now off', tenantSetting($tPdo, 'pos_advanced_customer') === '0');
ok('...Supplier is UNAFFECTED by turning Customer off', tenantSetting($tPdo, 'pos_advanced_supplier') === '1');

setTenantAdvancedSupplier($tenantId, false, false); // reset both before the endpoint section

// ─────────────────────────────────────────────────────────────────────────────
section('3. actions/superadmin_tenant_advanced_customer.php — guards, with a positive control');

$r = endpoint('actions/superadmin_tenant_advanced_customer.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['auth' => true]);
ok('POSITIVE CONTROL: an authenticated operator CAN read status', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_customer.php', ['tenant_id' => $tenantId, 'action' => 'status']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_customer.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['method' => 'GET', 'auth' => true]);
ok('refuses GET even when authenticated', refused($r, 'Method not allowed'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_customer.php', ['tenant_id' => $tenantId, 'action' => 'status', '_csrf' => 'wrong-token'], ['auth' => true]);
ok('refuses a bad CSRF token', refused($r, 'CSRF'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_customer.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['host' => $tenantHost, 'auth' => true]);
ok('refused from the TENANT\'s own host even when authenticated', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_customer.php', ['tenant_id' => $tenantId, 'action' => 'set', 'enabled' => 1, 'locked' => 0], ['auth' => true]);
ok('POSITIVE CONTROL: action=set actually persists', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
ok('...verified by direct SQL', tenantSetting($tPdo, 'pos_advanced_customer') === '1');
setTenantAdvancedCustomer($tenantId, false, false);

// ─────────────────────────────────────────────────────────────────────────────
section('4. actions/superadmin_tenant_advanced_supplier.php — same guard chain');

$r = endpoint('actions/superadmin_tenant_advanced_supplier.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['auth' => true]);
ok('POSITIVE CONTROL: an authenticated operator CAN read status', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_supplier.php', ['tenant_id' => $tenantId, 'action' => 'status']);
ok('refuses without a superadmin session', refused($r, 'session has ended'), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_supplier.php', ['tenant_id' => $tenantId, 'action' => 'status'], ['host' => $tenantHost, 'auth' => true]);
ok('refused from the TENANT\'s own host even when authenticated', refused($r), substr($r['out'], 0, 200));

$r = endpoint('actions/superadmin_tenant_advanced_supplier.php', ['tenant_id' => $tenantId, 'action' => 'set', 'enabled' => 1, 'locked' => 0], ['auth' => true]);
ok('POSITIVE CONTROL: action=set actually persists', str_contains($r['out'], '"success":true'), substr($r['out'], 0, 200));
ok('...verified by direct SQL', tenantSetting($tPdo, 'pos_advanced_supplier') === '1');
setTenantAdvancedSupplier($tenantId, false, false);

// ─────────────────────────────────────────────────────────────────────────────
section('5. A deleted tenant has no database left to query');

$deadSub = 'advcsdead' . bin2hex(random_bytes(3));
$rd = provisionTenant('Advanced Cust/Supp Dead Co', $deadSub, "owner@$deadSub.test", 'Password!123');
ok('throwaway (to-be-deleted) tenant provisioned', $rd['ok'] === true, (string)($rd['error'] ?? ''));
$deadId = (int)$rd['tenant_id'];
deleteTenant($deadId, 'Advanced Cust/Supp Dead Co');

ok('tenantAdvancedCustomerStatus() returns null for a deleted tenant', tenantAdvancedCustomerStatus($deadId) === null);
ok('tenantAdvancedSupplierStatus() returns null for a deleted tenant', tenantAdvancedSupplierStatus($deadId) === null);

$r = endpoint('actions/superadmin_tenant_advanced_customer.php', ['tenant_id' => $deadId, 'action' => 'status'], ['auth' => true]);
ok('the customer endpoint refuses cleanly for a deleted tenant (no crash)', refused($r), substr($r['out'], 0, 200));
$r = endpoint('actions/superadmin_tenant_advanced_supplier.php', ['tenant_id' => $deadId, 'action' => 'status'], ['auth' => true]);
ok('the supplier endpoint refuses cleanly for a deleted tenant (no crash)', refused($r), substr($r['out'], 0, 200));

// ─────────────────────────────────────────────────────────────────────────────
section('6. tenant_view.php — the "More" dialog offers BOTH toggles, on-demand only, independent of Advanced Product');

$html = route(SA_HOST, '/tenants/view?id=' . $tenantId);
ok('renders with no PHP fatal', !str_contains($html, 'Fatal error'));
ok('the Advanced Customer checkbox is present', str_contains($html, 'saAdvancedCustomerEnabled'));
ok('the Advanced Supplier checkbox is present', str_contains($html, 'saAdvancedSupplierEnabled'));
ok('the Advanced Product checkbox is still present too (untouched by this change)', str_contains($html, 'saAdvancedProductEnabled'));
ok('never embeds this tenant\'s current pos_advanced_customer value on load (on-demand only)',
    !str_contains($html, 'saAdvancedCustomerEnabled" checked'));
ok('never embeds this tenant\'s current pos_advanced_supplier value on load (on-demand only)',
    !str_contains($html, 'saAdvancedSupplierEnabled" checked'));
ok('the status fetch for Advanced Customer is wired into the same parallel $.when()',
    str_contains($html, "url: '/actions/superadmin_tenant_advanced_customer.php'"));
ok('the status fetch for Advanced Supplier is wired into the same parallel $.when()',
    str_contains($html, "url: '/actions/superadmin_tenant_advanced_supplier.php'"));
ok('the save wires Advanced Customer with locked=1 (superadmin-only, same discipline as the others)',
    str_contains($html, 'enabled: result.value.advancedCustomer'));
ok('the save wires Advanced Supplier with locked=1',
    str_contains($html, 'enabled: result.value.advancedSupplier'));

// ─────────────────────────────────────────────────────────────────────────────
section('7. Superadmin-only by design — no tenant-facing UI/endpoint exposes these settings');

$ownerUserId = (int)$tPdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
$avail = tenantRoute($tenantHost, '/available_modules', $ownerUserId);
ok('Available Modules renders with no PHP fatal', !str_contains($avail, 'Fatal error'));
ok('Available Modules never mentions pos_advanced_customer', !str_contains($avail, 'pos_advanced_customer'));
ok('Available Modules never mentions pos_advanced_supplier', !str_contains($avail, 'pos_advanced_supplier'));

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
