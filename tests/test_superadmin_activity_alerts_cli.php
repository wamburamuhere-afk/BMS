<?php
/**
 * Mobile API upgrade — Phase 2: superadmin bell for company activity — CLI regression
 *   php tests/test_superadmin_activity_alerts_cli.php
 *
 * core/tenant_activity_alerts.php rings the superadmin bell once per company for:
 * first sign-in (web or app, instant), first product, first sale (scan), and no
 * activity for 7 / 14 / 30 days (scan; re-armed by new activity). Companies from
 * before the feature went live never ring "first" alerts, and only companies
 * active after it ring "inactive", so the first run does not flood the bell.
 *
 * Real web sign-in (actions/login.php) and app sign-in (api/mobile/login.php)
 * over HTTP on a PHP built-in server this test starts; throwaway companies;
 * the platform settings it touches are restored afterwards.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_activity_alerts.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$c = getControlPdo();
$saved = ['activity_alerts_since' => getPlatformSetting('activity_alerts_since', ''), 'activity_alerts_last_scan' => getPlatformSetting('activity_alerts_last_scan', '')];
$tenants = []; $server = null;
register_shutdown_function(function () use (&$tenants, &$server, $c, $saved) {
    if ($server) @proc_terminate($server);
    foreach ($tenants as $tid) {
        try { getProvisioningPdo()->exec("DROP DATABASE IF EXISTS `bms_t$tid`"); getProvisioningPdo()->exec("DROP USER IF EXISTS 'bms_u$tid'@'%'"); } catch (Throwable $e) {}
        foreach (['tenant_admin_log', 'tenant_provisioning_log', 'tenant_migration_log', 'vendor_link_queue', 'tenant_features', 'tenant_activity_alerts', 'superadmin_notifications'] as $tb) {
            try { $c->prepare("DELETE FROM $tb WHERE tenant_id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        $c->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tid]);
    }
    foreach ($saved as $k => $v) setPlatformSetting($k, $v === '' ? null : $v);
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Static');
foreach (['core/tenant_activity_alerts.php', 'core/superadmin_notifications.php', 'core/superadmin_ui.php', 'core/mobile_auth.php',
          'actions/login.php', 'actions/superadmin_activity_scan.php', 'api/cron/trial_enforcement.php', 'scripts/setup_control_db.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}
check(str_contains(file_get_contents("$root/actions/login.php"), "taaMarkActive(\$__tenantId, 'web')"), 'web sign-in uses taaMarkActive');
check(str_contains(file_get_contents("$root/core/mobile_auth.php"), "taaMarkActive(\$tid, 'app')"), 'app sign-in / use uses taaMarkActive');
check(str_contains(file_get_contents("$root/api/cron/trial_enforcement.php"), 'runTenantActivityAlerts()'), 'daily cron runs the scan');
check(str_contains(file_get_contents("$root/core/superadmin_ui.php"), "/actions/superadmin_activity_scan.php"), 'superadmin pages beacon the scan');
$enum = (string)$c->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'superadmin_notifications' AND COLUMN_NAME = 'type'")->fetchColumn();
check(str_contains($enum, "'first_login'") && str_contains($enum, "'inactive'"), 'bell accepts the new types (run scripts/setup_control_db.php)');

$base = tenantBaseDomain();
if (!tenantModeEnabled() || !$base) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }

// Feature "went live" 40 days ago for this run (restored afterwards).
setPlatformSetting('activity_alerts_since', date('Y-m-d H:i:s', time() - 40 * 86400));

$mk = function (string $prefix) use (&$tenants) {
    $sub = $prefix . substr(bin2hex(random_bytes(3)), 0, 5);
    $r = provisionTenant('Alert ' . strtoupper($prefix) . ' Co', $sub, 'alowner', 'Password1', ['status' => 'active', 'skip_welcome_email' => true]);
    if (!$r['ok']) throw new RuntimeException($r['error']);
    $tenants[] = (int)$r['tenant_id'];
    return [(int)$r['tenant_id'], $sub];
};
$bell = function (int $tid, ?string $type = null) use ($c): array {
    $sql = "SELECT type, body FROM superadmin_notifications WHERE tenant_id = ?" . ($type ? " AND type = ?" : '') . " ORDER BY id";
    $s = $c->prepare($sql); $s->execute($type ? [$tid, $type] : [$tid]); return $s->fetchAll(PDO::FETCH_ASSOC);
};
$tpdo = function (int $tid): PDO {
    $s = controlDbSettings();
    $p = new PDO("mysql:host={$s['host']};dbname=bms_t$tid;charset=utf8mb4", $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->exec("SET time_zone = '+03:00'");
    return $p;
};
$setActive = function (int $tid, ?int $daysAgo) use ($c) {
    $c->prepare("UPDATE tenants SET last_active_at = " . ($daysAgo === null ? 'NULL' : 'NOW() - INTERVAL ' . (int)$daysAgo . ' DAY') . " WHERE id = ?")->execute([$tid]);
};

[$webTid, $webSub] = $mk('artaw');
[$appTid, $appSub] = $mk('artaa');
pass('two test companies created');

$port = 18000 + random_int(0, 999);
$server = proc_open([PHP_BINARY, '-d', 'max_execution_time=0', '-S', "127.0.0.1:$port", '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
usleep(800000);
$post = function (string $host, string $path, array $data) use ($port): array {
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Host: $host"], CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode($body, true)];
};

// ─────────────────────────────────────────────────────────────────────────
section('2. First sign-in (instant)');
[$code, $j] = $post("$webSub.$base", '/actions/login.php', ['username' => 'alowner', 'password' => 'Password1']);
check(!empty($j['success']) || $code === 200, "web sign-in works (HTTP $code)");
$b = $bell($webTid, 'first_login');
check(count($b) === 1 && str_contains($b[0]['body'], 'signed in for the first time.'), 'web: one "First sign-in" in the bell');
$post("$webSub.$base", '/actions/login.php', ['username' => 'alowner', 'password' => 'Password1']);
check(count($bell($webTid, 'first_login')) === 1, 'a second web sign-in does not ring again');

[$code] = $post("$appSub.$base", '/api/mobile/login.php', ['username' => 'alowner', 'password' => 'Password1', 'device_name' => 'Phone']);
$b = $bell($appTid, 'first_login');
check($code === 200 && count($b) === 1 && str_contains($b[0]['body'], 'mobile app'), 'app: one "First sign-in … on the mobile app"');

// A company from before the feature went live never rings "first".
$c->prepare("UPDATE tenants SET created_at = NOW() - INTERVAL 60 DAY, last_active_at = NULL WHERE id = ?")->execute([$appTid]);
$c->prepare("DELETE FROM tenant_activity_alerts WHERE tenant_id = ?")->execute([$appTid]);
$c->prepare("DELETE FROM superadmin_notifications WHERE tenant_id = ?")->execute([$appTid]);
$post("$appSub.$base", '/api/mobile/login.php', ['username' => 'alowner', 'password' => 'Password1', 'device_name' => 'Phone']);
check(count($bell($appTid, 'first_login')) === 0, 'older company: no "first" alert (no flood on go-live)');
$c->prepare("UPDATE tenants SET created_at = NOW() WHERE id = ?")->execute([$appTid]);

// ─────────────────────────────────────────────────────────────────────────
section('3. First product / first sale (scan)');
$setActive($webTid, 0);
runTenantActivityAlerts();
check(count($bell($webTid, 'first_product')) === 0 && count($bell($webTid, 'first_sale')) === 0, 'nothing yet → no alert');
$tp = $tpdo($webTid);
$tp->exec("INSERT INTO products (product_name) VALUES ('Sugar 1kg')");
runTenantActivityAlerts();
check(count($bell($webTid, 'first_product')) === 1, 'first product → one alert');
$tp->exec("INSERT INTO pos_sales (receipt_number, payment_method, shift_id, user_id, sale_status) VALUES ('T-1', 'cash', 0, 1, 'on_hold')");
runTenantActivityAlerts();
check(count($bell($webTid, 'first_sale')) === 0, 'a held cart is not a sale');
$tp->exec("INSERT INTO pos_sales (receipt_number, payment_method, shift_id, user_id, sale_status) VALUES ('T-2', 'cash', 0, 1, 'completed')");
runTenantActivityAlerts();
runTenantActivityAlerts();
check(count($bell($webTid, 'first_sale')) === 1 && count($bell($webTid, 'first_product')) === 1, 'first sale → one alert; scanning again adds nothing');

// ─────────────────────────────────────────────────────────────────────────
section('4. Inactive 7 → 14 → 30 days');
$setActive($webTid, 8);
runTenantActivityAlerts(); runTenantActivityAlerts();
$b = $bell($webTid, 'inactive');
check(count($b) === 1 && str_contains($b[0]['body'], '7 days'), '8 days idle → "no activity for 7 days", once');
$c->prepare("DELETE FROM superadmin_notifications WHERE tenant_id = ? AND type = 'inactive'")->execute([$webTid]);   // bell dedupes per day
$setActive($webTid, 15);
runTenantActivityAlerts();
$b = $bell($webTid, 'inactive');
check(count($b) === 1 && str_contains($b[0]['body'], '14 days'), '15 days → "14 days"');
$c->prepare("DELETE FROM superadmin_notifications WHERE tenant_id = ? AND type = 'inactive'")->execute([$webTid]);
$setActive($webTid, 31);
runTenantActivityAlerts();
$b = $bell($webTid, 'inactive');
check(count($b) === 1 && str_contains($b[0]['body'], '30 days'), '31 days → "30 days"');

// Active again → re-armed; going idle again rings again.
$c->prepare("UPDATE tenant_activity_alerts SET sent_at = NOW() - INTERVAL 1 DAY WHERE tenant_id = ? AND alert LIKE 'inactive%'")->execute([$webTid]);
$setActive($webTid, 0);
runTenantActivityAlerts();
$left = (int)$c->query("SELECT COUNT(*) FROM tenant_activity_alerts WHERE tenant_id = $webTid AND alert LIKE 'inactive%'")->fetchColumn();
check($left === 0, 'activity again re-arms the inactive alerts');

// Straight to 30 days: only the 30-day alert rings, not 7 and 14 too.
$c->prepare("DELETE FROM superadmin_notifications WHERE tenant_id = ?")->execute([$appTid]);
$c->prepare("UPDATE tenants SET last_active_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', time() - 31 * 86400), $appTid]);
runTenantActivityAlerts();
$b = $bell($appTid, 'inactive');
check(count($b) === 1 && str_contains($b[0]['body'], '30 days'), 'idle 31 days with nothing sent → only the 30-day alert');

// Idle since before go-live → no inactive alert.
$c->prepare("DELETE FROM superadmin_notifications WHERE tenant_id = ?")->execute([$appTid]);
$c->prepare("DELETE FROM tenant_activity_alerts WHERE tenant_id = ?")->execute([$appTid]);
$setActive($appTid, 50);
runTenantActivityAlerts();
check(count($bell($appTid, 'inactive')) === 0, 'idle since before go-live → no alert');

// ─────────────────────────────────────────────────────────────────────────
section('5. Panel scan endpoint');
$sa = (getenv('SUPERADMIN_SUBDOMAIN') ?: 'superadmin') . ".$base";
$ch = curl_init("http://127.0.0.1:$port/actions/superadmin_activity_scan.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Host: $sa"], CURLOPT_TIMEOUT => 30]);
curl_exec($ch); $get = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
[$code] = $post($sa, '/actions/superadmin_activity_scan.php', []);
check($get === 405 && $code === 401, "GET → 405, not signed in → 401 (got $get / $code)");
$recent = array_column(getRecentSaNotifications(50), 'type');
check(in_array('first_sale', $recent, true) && in_array('first_login', $recent, true), 'the bell list returns the new types');
