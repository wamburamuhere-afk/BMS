<?php
/**
 * Mobile API upgrade — Phase 1: superadmin sees app activity — CLI regression
 *   php tests/test_mobile_last_active_cli.php
 *
 * Gap (2026-10-27): tenants.last_active_at was set only by web login
 * (actions/login.php), so a company that used only the Flutter app showed
 * "Never" in the superadmin panel. Now:
 *   - api/mobile/login.php stamps it on app sign-in;
 *   - core/mobile_auth.php stamps it on app use, at most once per 15 minutes
 *     per device (the token's previous last_used_at is the throttle);
 *   - tenantUserDirectory() reports where the last sign-in happened (app/web),
 *     the app's last use and the device, shown on the tenant page.
 *
 * Runs real HTTP calls with a Bearer token against a throwaway company, on a
 * PHP built-in server this test starts itself (tenant chosen by Host header).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_admin.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$tid = 0; $server = null;
register_shutdown_function(function () use (&$tid, &$server) {
    if ($server) { @proc_terminate($server); }
    if ($tid) {
        try { getProvisioningPdo()->exec("DROP DATABASE IF EXISTS `bms_t$tid`"); getProvisioningPdo()->exec("DROP USER IF EXISTS 'bms_u$tid'@'%'"); } catch (Throwable $e) {}
        $c = getControlPdo();
        foreach (['tenant_admin_log', 'tenant_provisioning_log', 'tenant_migration_log', 'vendor_link_queue', 'tenant_features'] as $tb) {
            try { $c->prepare("DELETE FROM $tb WHERE tenant_id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        $c->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tid]);
    }
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Static');
foreach (['core/mobile_auth.php', 'api/mobile/login.php', 'core/tenant_admin.php', 'app/superadmin/tenant_view.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}
check(str_contains(file_get_contents("$root/api/mobile/login.php"), 'mobileTouchTenantActive();'), 'app login stamps Last Active');
check(str_contains(file_get_contents("$root/app/superadmin/tenant_view.php"), 'function lastLoginCell(u)'), 'tenant page shows App/Web + device');

// ─────────────────────────────────────────────────────────────────────────
section('2. Live app calls');
$base = tenantBaseDomain();
if (!tenantModeEnabled() || !$base) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }

$sub = 'artla' . substr(bin2hex(random_bytes(3)), 0, 5);
$r = provisionTenant('Last Active Co', $sub, 'laowner', 'Password1', ['status' => 'active', 'skip_welcome_email' => true]);
if (!$r['ok']) { fail('provision: ' . $r['error']); exit; }
$tid = (int)$r['tenant_id'];
pass("test company $sub created");

$port = 18000 + random_int(0, 999);
$server = proc_open([PHP_BINARY, '-d', 'max_execution_time=0', '-S', "127.0.0.1:$port", '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
usleep(800000);
$host = "$sub.$base";
$http = function (string $method, string $path, array $data = [], ?string $token = null) use ($port, $host): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    $h = ["Host: $host"];
    if ($token) $h[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($body, true)];
};
$cpdo = getControlPdo();
$lastActive = function () use ($cpdo, $tid) { $s = $cpdo->prepare("SELECT last_active_at FROM tenants WHERE id = ?"); $s->execute([$tid]); return $s->fetchColumn(); };
$clear = function () use ($cpdo, $tid) { $cpdo->prepare("UPDATE tenants SET last_active_at = NULL WHERE id = ?")->execute([$tid]); };
$tpdo = new PDO("mysql:host=" . controlDbSettings()['host'] . ";dbname=bms_t$tid;charset=utf8mb4", controlDbSettings()['user'], controlDbSettings()['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tpdo->exec("SET time_zone = '+03:00'");

$clear();
[$code, $j] = $http('POST', '/api/mobile/login.php', ['username' => 'laowner', 'password' => 'Password1', 'device_name' => 'Test Phone A54']);
$token = $j['token'] ?? null;
check($code === 200 && $token, "app login works (HTTP $code)");
check($lastActive() !== null, 'app login sets Last Active');

$clear();
[$code] = $http('GET', '/api/mobile/me.php', [], $token);
check($code === 200 && $lastActive() !== null, 'first app call sets Last Active');

$clear();
$http('GET', '/api/mobile/me.php', [], $token);
check($lastActive() === null, 'a second call within 15 min does not write again (throttle)');

$tpdo->exec("UPDATE mobile_tokens SET last_used_at = NOW() - INTERVAL 20 MINUTE");
$http('GET', '/api/mobile/me.php', [], $token);
check($lastActive() !== null, 'after 15+ min idle, app use sets it again');

$clear();
[$code] = $http('GET', '/api/mobile/me.php', [], str_repeat('a', 64));
check($code === 401 && $lastActive() === null, 'a bad token is refused and changes nothing');

// ─────────────────────────────────────────────────────────────────────────
section('3. Superadmin user list');
$u = tenantUserDirectory($tid)[0] ?? [];
check(($u['last_login_via'] ?? null) === 'app', 'last sign-in shown as App');
check(($u['app_device'] ?? null) === 'Test Phone A54', 'device name shown');
check(!empty($u['app_last_used']), 'app last use shown');

$tpdo->exec("UPDATE mobile_tokens SET created_at = NOW() - INTERVAL 2 HOUR, last_used_at = NOW() - INTERVAL 1 HOUR");
$tpdo->exec("UPDATE users SET last_login = NOW()");   // a later web sign-in
$u = tenantUserDirectory($tid)[0] ?? [];
check(($u['last_login_via'] ?? null) === 'web', 'a later web sign-in is shown as Web');
check(($u['app_last_used'] ?? '') < ($u['last_login'] ?? ''), 'app use stays listed separately');
