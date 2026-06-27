<?php
/**
 * core/session_tracker.php
 * ------------------------
 * Login/logout session ledger + "time in system" helpers, backed by the
 * `user_sessions` table. Every function is best-effort — it must NEVER break
 * sign-in or sign-out, so all DB work is wrapped and failures are swallowed
 * (logged to error_log only).
 */

if (!function_exists('parseUserAgent')) {
    /**
     * Parse a raw User-Agent string into browser, OS, and device_type.
     * Returns ['browser'=>string, 'os'=>string, 'device_type'=>string].
     */
    function parseUserAgent(?string $ua): array
    {
        if (empty($ua)) return ['browser' => 'Unknown', 'os' => 'Unknown', 'device_type' => 'Unknown'];

        // Device type
        if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/mobile|iphone|ipod|android.*mobile|blackberry|opera mini|iemobile|wpdesktop/i', $ua)) {
            $device = 'Mobile';
        } else {
            $device = 'Desktop';
        }

        // Browser
        if (preg_match('/Edg\//i', $ua))           $browser = 'Edge';
        elseif (preg_match('/OPR\//i', $ua))        $browser = 'Opera';
        elseif (preg_match('/SamsungBrowser/i', $ua)) $browser = 'Samsung Browser';
        elseif (preg_match('/UCBrowser/i', $ua))    $browser = 'UC Browser';
        elseif (preg_match('/Chrome/i', $ua))       $browser = 'Chrome';
        elseif (preg_match('/Firefox/i', $ua))      $browser = 'Firefox';
        elseif (preg_match('/Safari/i', $ua))       $browser = 'Safari';
        elseif (preg_match('/MSIE|Trident/i', $ua)) $browser = 'Internet Explorer';
        else                                         $browser = 'Other';

        // OS
        if (preg_match('/Windows NT 10/i', $ua)) {
            // Chrome/Edge send Sec-CH-UA-Platform-Version: platform major >= 13 = Windows 11
            $chVer = trim($_SERVER['HTTP_SEC_CH_UA_PLATFORM_VERSION'] ?? '', '"');
            if ($chVer !== '' && version_compare(explode('.', $chVer)[0], '13', '>=')) {
                $os = 'Windows 11';
            } elseif ($chVer !== '') {
                $os = 'Windows 10';
            } else {
                $os = 'Windows 10/11'; // Firefox/older browsers — genuinely ambiguous
            }
        } elseif (preg_match('/Windows NT 6\.3/i', $ua))  $os = 'Windows 8.1';
        elseif (preg_match('/Windows NT 6\.1/i', $ua))  $os = 'Windows 7';
        elseif (preg_match('/Windows/i', $ua))          $os = 'Windows';
        elseif (preg_match('/iPhone.*OS ([\d_]+)/i', $ua, $m)) $os = 'iOS ' . str_replace('_', '.', $m[1]);
        elseif (preg_match('/iPad.*OS ([\d_]+)/i', $ua, $m))   $os = 'iPadOS ' . str_replace('_', '.', $m[1]);
        elseif (preg_match('/Android ([\d.]+)/i', $ua, $m))    $os = 'Android ' . $m[1];
        elseif (preg_match('/Mac OS X/i', $ua))         $os = 'macOS';
        elseif (preg_match('/Linux/i', $ua))             $os = 'Linux';
        else                                             $os = 'Other';

        return ['browser' => $browser, 'os' => $os, 'device_type' => $device];
    }
}

if (!function_exists('lookupGeoIP')) {
    /**
     * Call ip-api.com to resolve an IP to city/country/ISP/org/timezone.
     * Returns an array or null on failure. 45-req/min free limit is fine for logins.
     * Never called for loopback/private IPs.
     */
    function lookupGeoIP(?string $ip): ?array
    {
        if (empty($ip)) return null;
        // Skip private/loopback addresses — no geo data available
        if (in_array($ip, ['127.0.0.1', '::1'], true) || substr($ip, 0, 3) === '10.'
            || substr($ip, 0, 4) === '192.' || substr($ip, 0, 7) === '172.16.'
        ) {
            return ['city' => 'Local', 'region' => '', 'country' => 'Local', 'country_code' => '--',
                    'isp' => 'Internal Network', 'org' => 'Internal', 'timezone' => date_default_timezone_get()];
        }
        try {
            $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,city,regionName,country,countryCode,isp,org,timezone';
            $ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) return null;
            $data = json_decode($raw, true);
            if (!$data || ($data['status'] ?? '') !== 'success') return null;
            return [
                'city'         => $data['city']        ?? null,
                'region'       => $data['regionName']  ?? null,
                'country'      => $data['country']     ?? null,
                'country_code' => $data['countryCode'] ?? null,
                'isp'          => $data['isp']         ?? null,
                'org'          => $data['org']         ?? null,
                'timezone'     => $data['timezone']    ?? null,
            ];
        } catch (Throwable $e) {
            error_log('lookupGeoIP: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('startUserSession')) {
    /**
     * Open a session row on successful login. Enriches with GeoIP + parsed UA.
     * Returns the new row id (to stash in $_SESSION) or null on failure.
     */
    function startUserSession(PDO $pdo, int $userId, ?string $ip = null, ?string $ua = null): ?int
    {
        if ($userId <= 0) return null;
        try {
            $geo    = lookupGeoIP($ip);
            $device = parseUserAgent($ua);

            $stmt = $pdo->prepare(
                "INSERT INTO user_sessions
                    (user_id, login_at, ip_address, user_agent,
                     city, region, country, country_code, isp, org, timezone,
                     browser, os, device_type)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $ip !== null ? substr($ip, 0, 45)  : null,
                $ua !== null ? substr($ua, 0, 255)  : null,
                $geo['city']         ?? null,
                $geo['region']       ?? null,
                $geo['country']      ?? null,
                $geo['country_code'] ?? null,
                $geo['isp']          ?? null,
                $geo['org']          ?? null,
                $geo['timezone']     ?? null,
                $device['browser'],
                $device['os'],
                $device['device_type'],
            ]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('startUserSession: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('endUserSession')) {
    /**
     * Close a session row on logout: stamp logout_at + compute duration_seconds.
     * Idempotent (skips rows already closed). Returns the duration in seconds, or
     * null if it couldn't be closed.
     */
    function endUserSession(PDO $pdo, ?int $sessionRowId, string $logoutType = 'manual'): ?int
    {
        if (!$sessionRowId) return null;
        try {
            // Only close if still open — never overwrite a real logout time.
            $row = $pdo->prepare("SELECT login_at, logout_at FROM user_sessions WHERE id = ?");
            $row->execute([$sessionRowId]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['logout_at'] !== null) return null;

            $dur = max(0, time() - strtotime($r['login_at']));
            $upd = $pdo->prepare(
                "UPDATE user_sessions
                    SET logout_at = NOW(), duration_seconds = ?, logout_type = ?
                  WHERE id = ? AND logout_at IS NULL"
            );
            $upd->execute([$dur, substr($logoutType, 0, 20), $sessionRowId]);
            return $dur;
        } catch (Throwable $e) {
            error_log('endUserSession: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('formatDuration')) {
    /**
     * Human, audit-friendly duration: "2h 15m", "45m 03s", "38s", or "—" for
     * null (an open/unknown session).
     */
    function formatDuration(?int $seconds): string
    {
        if ($seconds === null) return '—';
        $seconds = max(0, (int) $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) return sprintf('%dh %02dm', $h, $m);
        if ($m > 0) return sprintf('%dm %02ds', $m, $s);
        return sprintf('%ds', $s);
    }
}

if (!function_exists('userSessionSummary')) {
    /**
     * Per-user "time in system" summary over an optional date range. Only closed
     * sessions count toward totals (open ones can't be measured). Returns:
     *   ['sessions'=>int,'closed'=>int,'open'=>int,'total_seconds'=>int,
     *    'avg_seconds'=>?int,'last_login'=>?string,'last_logout'=>?string]
     */
    function userSessionSummary(PDO $pdo, int $userId, ?string $from = null, ?string $to = null): array
    {
        $out = ['sessions'=>0,'closed'=>0,'open'=>0,'total_seconds'=>0,'avg_seconds'=>null,'last_login'=>null,'last_logout'=>null];
        if ($userId <= 0) return $out;
        try {
            $where = "user_id = ?";
            $params = [$userId];
            if ($from) { $where .= " AND login_at >= ?"; $params[] = $from; }
            if ($to)   { $where .= " AND login_at <= ?"; $params[] = $to; }

            $st = $pdo->prepare("
                SELECT COUNT(*) AS sessions,
                       SUM(CASE WHEN logout_at IS NOT NULL THEN 1 ELSE 0 END) AS closed,
                       SUM(CASE WHEN logout_at IS NULL THEN 1 ELSE 0 END) AS open,
                       COALESCE(SUM(duration_seconds), 0) AS total_seconds,
                       MAX(login_at) AS last_login,
                       MAX(logout_at) AS last_logout
                  FROM user_sessions WHERE $where
            ");
            $st->execute($params);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['sessions']      = (int) ($r['sessions'] ?? 0);
            $out['closed']        = (int) ($r['closed'] ?? 0);
            $out['open']          = (int) ($r['open'] ?? 0);
            $out['total_seconds'] = (int) ($r['total_seconds'] ?? 0);
            $out['avg_seconds']   = $out['closed'] > 0 ? (int) round($out['total_seconds'] / $out['closed']) : null;
            $out['last_login']    = $r['last_login'] ?? null;
            $out['last_logout']   = $r['last_logout'] ?? null;
        } catch (Throwable $e) {
            error_log('userSessionSummary: ' . $e->getMessage());
        }
        return $out;
    }
}
