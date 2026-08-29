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
     *
     * Before inserting, closes any session this user left open — browser closed
     * without logging out, a crash, or simply logging in again elsewhere. Ends it
     * at its own last-seen moment (never invents a logout time nobody observed)
     * and tags it 'superseded', so Login History reads "Signed in again" instead
     * of an "Active" row that would otherwise sit open forever.
     */
    function startUserSession(PDO $pdo, int $userId, ?string $ip = null, ?string $ua = null, ?string $phpSessionId = null): ?int
    {
        if ($userId <= 0) return null;
        try {
            $openRows = $pdo->prepare("SELECT id, login_at, last_seen_at FROM user_sessions WHERE user_id = ? AND logout_at IS NULL");
            $openRows->execute([$userId]);
            foreach ($openRows->fetchAll(PDO::FETCH_ASSOC) as $open) {
                $asOf = $open['last_seen_at'] ?? $open['login_at'];
                $dur  = max(0, strtotime($asOf) - strtotime($open['login_at']));
                $pdo->prepare(
                    "UPDATE user_sessions SET logout_at = ?, duration_seconds = ?, logout_type = 'superseded'
                      WHERE id = ? AND logout_at IS NULL"
                )->execute([$asOf, $dur, $open['id']]);
            }

            $geo    = lookupGeoIP($ip);
            $device = parseUserAgent($ua);

            $stmt = $pdo->prepare(
                "INSERT INTO user_sessions
                    (user_id, php_session_id, login_at, last_seen_at, ip_address, user_agent,
                     city, region, country, country_code, isp, org, timezone,
                     browser, os, device_type)
                 VALUES (?, ?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $phpSessionId !== null ? substr($phpSessionId, 0, 128) : null,
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
     * Close a session row: stamp logout_at + compute duration_seconds.
     * Idempotent (skips rows already closed). Returns the duration in seconds, or
     * null if it couldn't be closed.
     *
     * $asOf lets a caller close using a specific moment rather than "now" — used
     * by expireIdleSessions() to end an idle row at its true last-seen time, never
     * a fabricated one. Omit it (the normal manual-logout call) and the database's
     * own NOW() is used, exactly as before.
     */
    function endUserSession(PDO $pdo, ?int $sessionRowId, string $logoutType = 'manual', ?string $asOf = null): ?int
    {
        if (!$sessionRowId) return null;
        try {
            // Only close if still open — never overwrite a real logout time.
            $row = $pdo->prepare("SELECT login_at, logout_at FROM user_sessions WHERE id = ?");
            $row->execute([$sessionRowId]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['logout_at'] !== null) return null;

            if ($asOf !== null) {
                $dur = max(0, strtotime($asOf) - strtotime($r['login_at']));
                $upd = $pdo->prepare(
                    "UPDATE user_sessions SET logout_at = ?, duration_seconds = ?, logout_type = ?
                      WHERE id = ? AND logout_at IS NULL"
                );
                $upd->execute([$asOf, $dur, substr($logoutType, 0, 20), $sessionRowId]);
            } else {
                $dur = max(0, time() - strtotime($r['login_at']));
                $upd = $pdo->prepare(
                    "UPDATE user_sessions SET logout_at = NOW(), duration_seconds = ?, logout_type = ?
                      WHERE id = ? AND logout_at IS NULL"
                );
                $upd->execute([$dur, substr($logoutType, 0, 20), $sessionRowId]);
            }
            return $dur;
        } catch (Throwable $e) {
            error_log('endUserSession: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('touchUserSession')) {
    /**
     * Heartbeat: bump last_seen_at for an open session so an idle timeout has a
     * real last-active moment to expire from, instead of guessing from login_at.
     * Throttled server-side (default 60s) regardless of how often the caller
     * pings, so normal navigation can never generate a write per request.
     */
    function touchUserSession(PDO $pdo, ?int $sessionRowId, int $minIntervalSeconds = 60): void
    {
        if (!$sessionRowId) return;
        try {
            $pdo->prepare(
                "UPDATE user_sessions SET last_seen_at = NOW()
                  WHERE id = ? AND logout_at IS NULL
                    AND (last_seen_at IS NULL OR last_seen_at < (NOW() - INTERVAL ? SECOND))"
            )->execute([$sessionRowId, $minIntervalSeconds]);
        } catch (Throwable $e) {
            error_log('touchUserSession: ' . $e->getMessage());
        }
    }
}

if (!function_exists('expireIdleSessions')) {
    /**
     * Close every session that has gone quiet past $idleSeconds. Set-based (one
     * UPDATE, no loop) so it is cheap enough to run from a throttled header.php
     * include exactly like cron/check_hr_expiry.php — BMS has no OS-level cron,
     * everything real-world piggybacks on ordinary page loads.
     *
     * Closes at COALESCE(last_seen_at, login_at) — the row's own last-observed
     * moment — never "now". A session nobody has touched in 45 minutes should not
     * show a duration that includes the 45 minutes nobody was there.
     * Returns the number of rows closed.
     */
    function expireIdleSessions(PDO $pdo, int $idleSeconds = 1800): int
    {
        try {
            // GREATEST(0, ...) is defensive, not load-bearing: last_seen_at is only
            // ever advanced by touchUserSession()'s own NOW(), so it cannot really
            // precede login_at — but a negative duration must never reach the UI
            // regardless of how it happened.
            $stmt = $pdo->prepare(
                "UPDATE user_sessions
                    SET logout_at = COALESCE(last_seen_at, login_at),
                        duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, login_at, COALESCE(last_seen_at, login_at))),
                        logout_type = 'timeout'
                  WHERE logout_at IS NULL
                    AND COALESCE(last_seen_at, login_at) < (NOW() - INTERVAL ? SECOND)"
            );
            $stmt->execute([$idleSeconds]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('expireIdleSessions: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('revokeUserSession')) {
    /**
     * Admin action: forcibly end a live session. Enforced on the target user's
     * NEXT request (see bmsEnforceSessionLifecycle()) — BMS has no websocket/push
     * layer, so "instant across every open tab" is not achievable without new
     * infrastructure; ending it on the next request they take is the standard,
     * honest version of this feature.
     *
     * $reason distinguishes a security action ('revoked' — e.g. suspected
     * compromise, stolen device) from routine housekeeping ('admin_ended' — e.g.
     * tidying a stale row) so the audit trail can tell them apart.
     */
    function revokeUserSession(PDO $pdo, int $sessionRowId, int $adminUserId, string $reason = 'revoked'): bool
    {
        $reason = in_array($reason, ['revoked', 'admin_ended'], true) ? $reason : 'revoked';
        try {
            $row = $pdo->prepare("SELECT login_at, logout_at FROM user_sessions WHERE id = ?");
            $row->execute([$sessionRowId]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['logout_at'] !== null) return false;

            $dur = max(0, time() - strtotime($r['login_at']));
            $upd = $pdo->prepare(
                "UPDATE user_sessions
                    SET logout_at = NOW(), duration_seconds = ?, logout_type = ?,
                        revoked_by = ?, revoked_at = NOW()
                  WHERE id = ? AND logout_at IS NULL"
            );
            $upd->execute([$dur, $reason, $adminUserId, $sessionRowId]);
            return $upd->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('revokeUserSession: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bmsEnforceSessionLifecycle')) {
    /**
     * Runs once per authenticated request (called from roots.php). If this
     * browser's own user_sessions row has been closed by something OTHER than
     * this request's own logout.php call — an admin revoked it, the idle sweep
     * expired it, or the user signed in again elsewhere and superseded it — the
     * system has already ended this session even though the browser doesn't know
     * it yet. Sign this tab out too, rather than let it keep acting as a user
     * whose access was actually withdrawn.
     *
     * A plain page load is redirected to the login page with the reason. An
     * API/AJAX call just has its $_SESSION cleared — isAuthenticated() then
     * correctly reports false for the rest of that request, which is the right
     * behaviour for a JSON endpoint (no HTML redirect to send).
     */
    function bmsEnforceSessionLifecycle(PDO $pdo): void
    {
        if (empty($_SESSION['user_id']) || empty($_SESSION['session_row_id'])) return;
        try {
            $st = $pdo->prepare("SELECT logout_at, logout_type FROM user_sessions WHERE id = ?");
            $st->execute([(int) $_SESSION['session_row_id']]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['logout_at'] === null) return;   // still open — normal case, nothing to do

            $reason = $r['logout_type'] ?: 'ended';
            $_SESSION = [];

            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $isApiLike = strpos($uri, '/api/') !== false
                || strpos($uri, '/ajax') !== false
                || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

            if (!$isApiLike && !headers_sent() && function_exists('getUrl')) {
                header('Location: ' . getUrl('login') . '?ended=' . urlencode($reason));
                exit;
            }
        } catch (Throwable $e) {
            error_log('bmsEnforceSessionLifecycle: ' . $e->getMessage());
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
