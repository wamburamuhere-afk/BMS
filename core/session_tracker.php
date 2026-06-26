<?php
/**
 * core/session_tracker.php
 * ------------------------
 * Login/logout session ledger + "time in system" helpers, backed by the
 * `user_sessions` table. Every function is best-effort — it must NEVER break
 * sign-in or sign-out, so all DB work is wrapped and failures are swallowed
 * (logged to error_log only).
 */

if (!function_exists('startUserSession')) {
    /**
     * Open a session row on successful login. Returns the new row id (to stash
     * in $_SESSION) or null on failure.
     */
    function startUserSession(PDO $pdo, int $userId, ?string $ip = null, ?string $ua = null): ?int
    {
        if ($userId <= 0) return null;
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO user_sessions (user_id, login_at, ip_address, user_agent)
                 VALUES (?, NOW(), ?, ?)"
            );
            $stmt->execute([
                $userId,
                $ip !== null ? substr($ip, 0, 45) : null,
                $ua !== null ? substr($ua, 0, 255) : null,
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
