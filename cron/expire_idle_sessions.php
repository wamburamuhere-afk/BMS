<?php
/**
 * cron/expire_idle_sessions.php
 *
 * Closes user_sessions rows nobody has touched in 30 minutes (matching the same
 * idle timeout app/bms/pos/includes/security.php already enforces at the PHP
 * session layer) — a session ends at its own last-seen moment, never "now", per
 * expireIdleSessions() in core/session_tracker.php.
 *
 * BMS has no OS-level cron; this piggybacks on real traffic exactly like
 * cron/check_hr_expiry.php, throttled from roots.php so it runs at most once
 * every few minutes regardless of how many requests land in between.
 * Self-contained and fail-silent — must never break a page load.
 */
try {
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        require_once __DIR__ . '/../core/session_tracker.php';
        expireIdleSessions($pdo, 1800);
    }
} catch (Throwable $e) {
    error_log('expire_idle_sessions: ' . $e->getMessage());
}
