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
     * Does NOT close any prior open session for this user — see the "only two
     * automatic causes" note inside the function body. A prior open row just
     * triggers an admin email (notifyConcurrentLogin()); both rows stay
     * "Active" until an admin manually ends one.
     */
    function startUserSession(PDO $pdo, int $userId, ?string $ip = null, ?string $ua = null, ?string $phpSessionId = null): ?int
    {
        if ($userId <= 0) return null;
        try {
            // Only TWO things may ever automatically end a session: the person
            // clicking Logout, and the 30-minute idle timeout
            // (expireIdleSessions()). Logging in again while a previous session
            // is still open used to auto-close that earlier row ("superseded") —
            // deliberately removed (2026-08-29, the user's own decision after
            // review). It is now a pure SIGNAL: count how many sessions are
            // already open, email admins if there are any, and touch NOTHING —
            // both rows stay genuinely "Active" until an admin reviews Login
            // History and manually ends one, exactly as intended.
            $priorOpenStmt = $pdo->prepare("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND logout_at IS NULL");
            $priorOpenStmt->execute([$userId]);
            $priorOpenCount = (int) $priorOpenStmt->fetchColumn();

            $geo    = lookupGeoIP($ip);
            $device = parseUserAgent($ua);

            // Computed BEFORE the insert — checks whether any PRIOR row for this
            // user already used this country+device. Same logic as
            // UNFAMILIAR_SQL in api/get_login_history.php (that one re-derives it
            // for display, across many rows, at browse time; this is the one
            // live check that can actually act on a login as it happens).
            $unfamiliar = isUnfamiliarLogin($pdo, $userId, $geo['country'] ?? null, $device['device_type'] ?? null);

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
            $newRowId = (int) $pdo->lastInsertId();

            if ($unfamiliar) {
                // Isolated try/catch: a notification problem must never make
                // startUserSession() itself fail or skip returning the row id —
                // this file's own rule, restated at the top.
                try {
                    notifyUnfamiliarLogin($pdo, $userId, $newRowId, $geo, $device, $ip);
                } catch (Throwable $e) {
                    error_log('startUserSession/notifyUnfamiliarLogin: ' . $e->getMessage());
                }
            }

            if ($priorOpenCount > 0) {
                try {
                    notifyConcurrentLogin($pdo, $userId, $newRowId, $priorOpenCount);
                } catch (Throwable $e) {
                    error_log('startUserSession/notifyConcurrentLogin: ' . $e->getMessage());
                }
            }

            return $newRowId;
        } catch (Throwable $e) {
            error_log('startUserSession: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('isUnfamiliarLogin')) {
    /**
     * True the first time this user has ever signed in from this
     * country+device combination. Single source of truth for the LIVE check
     * (called from startUserSession(), before the new row exists) — the
     * display-time equivalent for existing rows is UNFAMILIAR_SQL in
     * api/get_login_history.php; both must apply the same rule: real
     * country (not empty/'Local') + real device_type, never seen together
     * before for this user.
     */
    function isUnfamiliarLogin(PDO $pdo, int $userId, ?string $country, ?string $deviceType): bool
    {
        if (empty($country) || $country === 'Local' || empty($deviceType)) return false;
        try {
            $st = $pdo->prepare(
                "SELECT 1 FROM user_sessions
                  WHERE user_id = ? AND country = ? AND device_type = ?
                  LIMIT 1"
            );
            $st->execute([$userId, $country, $deviceType]);
            return $st->fetchColumn() === false; // no prior row -> unfamiliar
        } catch (Throwable $e) {
            error_log('isUnfamiliarLogin: ' . $e->getMessage());
            return false; // never let a lookup failure block or flag a login
        }
    }
}

if (!function_exists('notifyUnfamiliarLogin')) {
    /**
     * Fires on a genuinely unfamiliar login. Pure signal, no automatic
     * session action — the user's own final decision (2026-08-29): only two
     * things may ever automatically end a session (Logout click, 30-minute
     * idle timeout). This always emails admins, independent of the general
     * "enable email notifications" toggle, via the forced notification_rules
     * row this feature's migration seeds. There used to be an admin-facing
     * auto-logout opt-in setting here — removed entirely, not just defaulted
     * off, per that decision.
     */
    function notifyUnfamiliarLogin(PDO $pdo, int $userId, int $sessionRowId, array $geo, array $device, ?string $ip): void
    {
        if (!function_exists('dispatchEvent')) {
            require_once __DIR__ . '/notify.php';
        }
        if (!function_exists('dispatchEvent')) return; // engine unavailable — never block login over this

        $u = $pdo->prepare("SELECT username, email FROM users WHERE user_id = ?");
        $u->execute([$userId]);
        $user = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        $who  = $user['username'] ?? ('user #' . $userId);

        $where = trim(implode(', ', array_filter([$geo['city'] ?? '', $geo['country'] ?? '']))) ?: 'an unknown location';
        $what  = trim(($device['browser'] ?? '') . ' on ' . ($device['os'] ?? '') . ' (' . ($device['device_type'] ?? '') . ')');

        dispatchEvent($pdo, 'unfamiliar_login_detected', [
            'title'       => 'Unfamiliar login: ' . $who,
            'message'     => "$who signed in from $where using $what — the first time this account has been seen from that country and device combination." . ($ip ? " IP: $ip." : ''),
            'severity'    => 'high',
            'action_url'  => 'login_history',
            'entity_type' => 'user_session',
            'entity_id'   => $sessionRowId,
            // Once per session row, not once per day like most events — each
            // unfamiliar login is its own distinct thing worth its own alert.
            'dedupe_suffix' => (string) $sessionRowId,
        ]);
    }
}

if (!function_exists('notifyConcurrentLogin')) {
    /**
     * Fires when this account signs in while a PREVIOUS session for the same
     * account is still open (second device/browser, or someone else with the
     * same password). Pure signal, same as notifyUnfamiliarLogin() — no
     * automatic session action. Both rows are left genuinely "Active"; an
     * admin reviews Login History and manually End Sessions the one that
     * shouldn't be there. This replaced the old auto-close-on-relogin
     * ("superseded") behavior per the user's own explicit decision.
     */
    function notifyConcurrentLogin(PDO $pdo, int $userId, int $newSessionRowId, int $priorOpenCount): void
    {
        if (!function_exists('dispatchEvent')) {
            require_once __DIR__ . '/notify.php';
        }
        if (!function_exists('dispatchEvent')) return; // engine unavailable — never block login over this

        $u = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $u->execute([$userId]);
        $who = $u->fetchColumn() ?: ('user #' . $userId);

        $totalOpen = $priorOpenCount + 1;

        dispatchEvent($pdo, 'concurrent_login_detected', [
            'title'       => 'Concurrent login: ' . $who,
            'message'     => "$who has signed in again while " . ($priorOpenCount === 1 ? 'another session is' : "$priorOpenCount other sessions are") . " still open (now $totalOpen active). Review Login History to see both and End Session on any that shouldn't be there.",
            'severity'    => 'high',
            'action_url'  => 'login_history',
            'entity_type' => 'user_session',
            'entity_id'   => $newSessionRowId,
            // Once per new session row — each fresh concurrent login is its
            // own distinct event worth its own alert.
            'dedupe_suffix' => (string) $newSessionRowId,
        ]);
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
        // 'blocked' — the account itself was deactivated (ajax/toggle_user.php),
        // distinct from 'revoked' (a single session force-ended while the
        // account stays usable) so Login History's Ended column can say
        // "Account Blocked" rather than a generic "Revoked".
        $reason = in_array($reason, ['revoked', 'admin_ended', 'blocked'], true) ? $reason : 'revoked';
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
     * this request's own logout.php call — an admin ended/revoked it, or the
     * idle sweep expired it (the only two automatic causes) — the system has
     * already ended this session even though the browser doesn't know it yet.
     * Sign this tab out too, rather than let it keep acting as a user whose
     * access was actually withdrawn.
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

if (!function_exists('recordPreciseLocation')) {
    /**
     * Attach a one-shot, consent-based browser-reported position to a session
     * row. Distinct from the always-on GeoIP columns (city/region/country —
     * "Approximate"): this only ever gets written if the user's browser actually
     * prompted them and they agreed, and never repeats once set.
     *
     * Both guards are enforced here, not just by the caller: WHERE
     * precise_captured_at IS NULL makes a second ping a no-op even if the
     * one-shot check on the JS/page side is ever bypassed, and WHERE
     * logout_at IS NULL refuses to attach a "current" position to a session
     * that has already ended.
     */
    function recordPreciseLocation(PDO $pdo, int $sessionRowId, float $lat, float $lng, ?int $accuracyM): bool
    {
        if ($sessionRowId <= 0) return false;
        // Real-world bounds only — malformed input must not masquerade as a
        // device-reported position an admin will trust as ground truth.
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;
        try {
            $stmt = $pdo->prepare(
                "UPDATE user_sessions
                    SET precise_lat = ?, precise_lng = ?, precise_accuracy_m = ?, precise_captured_at = NOW()
                  WHERE id = ? AND logout_at IS NULL AND precise_captured_at IS NULL"
            );
            $stmt->execute([$lat, $lng, $accuracyM, $sessionRowId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('recordPreciseLocation: ' . $e->getMessage());
            return false;
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
