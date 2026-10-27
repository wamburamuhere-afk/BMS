<?php
/**
 * tests/helpers/mobile_http.inc.php — real Flutter-style HTTP calls for CLI tests
 * (mobile API upgrade, mobile_api_upgrade_plan.md).
 *
 *   $m = mhStart('artx');            // throwaway company + PHP built-in server
 *   [$code, $json] = mhCall($m, 'POST', '/api/mobile/login.php', ['username'=>…], $token);
 *   $token = mhLogin($m, 'mhowner', 'Password1');
 *   $pdo   = mhTenantPdo($m);        // the company's own database (+03:00)
 *
 * The company (database, DB user, registry rows) and the server are removed at
 * shutdown. Owner: username mhowner, password Password1. JSON bodies are sent
 * as JSON; everything else as a form.
 */

require_once dirname(__DIR__, 2) . '/roots.php';
require_once dirname(__DIR__, 2) . '/core/control_db.php';
require_once dirname(__DIR__, 2) . '/core/tenant_provisioner.php';

if (!function_exists('mhStart')) {
    function mhStart(string $prefix): ?array
    {
        if (!tenantModeEnabled() || !tenantBaseDomain()) return null;
        $sub = $prefix . substr(bin2hex(random_bytes(3)), 0, 5);
        $r = provisionTenant('Mobile Test ' . strtoupper($prefix), $sub, 'mhowner', 'Password1',
            ['status' => 'active', 'skip_welcome_email' => true, 'owner_contact_email' => "owner@$sub.test"]);
        if (!$r['ok']) throw new RuntimeException('provision: ' . $r['error']);
        $tid  = (int)$r['tenant_id'];
        $root = dirname(__DIR__, 2);
        $port = 18000 + random_int(0, 1999);
        $srv  = proc_open([PHP_BINARY, '-d', 'max_execution_time=0', '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        usleep(800000);
        $m = ['tid' => $tid, 'sub' => $sub, 'host' => $sub . '.' . tenantBaseDomain(), 'port' => $port, 'srv' => $srv];
        register_shutdown_function(function () use ($m) { mhStop($m); });
        return $m;
    }

    function mhStop(array $m): void
    {
        static $done = [];
        if (isset($done[$m['tid']])) return;
        $done[$m['tid']] = true;
        // proc_terminate() alone can leave the built-in server running on Windows.
        $pid = (int)(@proc_get_status($m['srv'])['pid'] ?? 0);
        @proc_terminate($m['srv']);
        if ($pid > 0 && PHP_OS_FAMILY === 'Windows') @exec('taskkill /PID ' . $pid . ' /F /T 2>NUL');
        @proc_close($m['srv']);
        $tid = $m['tid'];
        try { getProvisioningPdo()->exec("DROP DATABASE IF EXISTS `bms_t$tid`"); getProvisioningPdo()->exec("DROP USER IF EXISTS 'bms_u$tid'@'%'"); } catch (Throwable $e) {}
        $c = getControlPdo();
        foreach (['tenant_admin_log', 'tenant_provisioning_log', 'tenant_migration_log', 'vendor_link_queue', 'tenant_features',
                  'tenant_activity_alerts', 'superadmin_notifications'] as $tb) {
            try { $c->prepare("DELETE FROM $tb WHERE tenant_id = ?")->execute([$tid]); } catch (Throwable $e) {}
        }
        $c->prepare("DELETE FROM tenants WHERE id = ?")->execute([$tid]);
    }

    /** @return array{0:int,1:?array,2:string} [HTTP code, decoded JSON, raw body] */
    function mhCall(array $m, string $method, string $path, array $data = [], ?string $token = null, bool $json = false): array
    {
        $url = "http://127.0.0.1:{$m['port']}$path";
        if ($method === 'GET' && $data) $url .= (str_contains($path, '?') ? '&' : '?') . http_build_query($data);
        $h = ["Host: {$m['host']}"];
        if ($token) $h[] = "Authorization: Bearer $token";
        $ch = curl_init($url);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CUSTOMREQUEST => $method];
        if ($method !== 'GET') {
            if ($json) { $h[] = 'Content-Type: application/json'; $opt[CURLOPT_POSTFIELDS] = json_encode($data); }
            else       { $opt[CURLOPT_POSTFIELDS] = http_build_query($data); }
        }
        $opt[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opt);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode($body, true), $body];
    }

    function mhLogin(array $m, string $user = 'mhowner', string $pw = 'Password1'): ?string
    {
        [, $j] = mhCall($m, 'POST', '/api/mobile/login.php', ['username' => $user, 'password' => $pw, 'device_name' => 'Test Phone']);
        return $j['token'] ?? null;
    }

    function mhTenantPdo(array $m): PDO
    {
        $s = controlDbSettings();
        $p = new PDO("mysql:host={$s['host']};dbname=bms_t{$m['tid']};charset=utf8mb4", $s['user'], $s['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $p->exec("SET time_zone = '+03:00'");
        return $p;
    }
}
