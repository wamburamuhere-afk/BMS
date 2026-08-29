<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: session lifecycle upgrade (Login History rebuild)...\n";

/**
 * Extends user_sessions to support a real session lifecycle, matching/exceeding
 * the reference LMS Login History implementation:
 *
 *  - last_seen_at     : heartbeat timestamp, updated on authenticated page loads
 *                       (throttled) so an idle session's true last-active moment
 *                       is known WITHOUT waiting for a close event.
 *  - php_session_id   : the PHP session id this row belongs to, so an admin
 *                       "End session" action (revokeUserSession()) can be
 *                       enforced on the target user's next request.
 *  - revoked_by/at    : who force-ended the session and when (audit trail for
 *                       'revoked'/'admin_ended' rows).
 *  - precise_lat/lng/accuracy_m/captured_at : one-shot, consent-based browser
 *                       geolocation, captured once at login. Distinct from the
 *                       existing GeoIP columns (Approximate) — never required,
 *                       always optional, never polled repeatedly.
 *
 * logout_type keeps its existing values ('manual','timeout') and gains three:
 *   'superseded' — the user logged in again while this row was still open
 *   'revoked'    — an admin forcibly ended a live session (security action)
 *   'admin_ended'— an admin ended a live session as routine housekeeping
 * (distinguished so an audit trail can tell "kicked out for cause" from
 * "an admin tidied up a stale row"). Column is left as-is (VARCHAR(20)) —
 * no ENUM change needed, no existing rows need rewriting.
 *
 * Purely additive + idempotent: every column is added only if missing.
 */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM user_sessions")->fetchAll(PDO::FETCH_COLUMN);

    $add = function (string $name, string $ddl) use ($pdo, $cols) {
        if (in_array($name, $cols, true)) {
            echo "  · $name already exists — skipped.\n";
            return;
        }
        $pdo->exec("ALTER TABLE user_sessions ADD COLUMN $ddl");
        echo "  + $name added.\n";
    };

    $add('last_seen_at',        'last_seen_at DATETIME NULL AFTER login_at');
    $add('php_session_id',      'php_session_id VARCHAR(128) NULL AFTER user_id');
    $add('revoked_by',          'revoked_by INT NULL AFTER logout_type');
    $add('revoked_at',          'revoked_at DATETIME NULL AFTER revoked_by');
    $add('precise_lat',         'precise_lat DECIMAL(10,7) NULL AFTER timezone');
    $add('precise_lng',         'precise_lng DECIMAL(10,7) NULL AFTER precise_lat');
    $add('precise_accuracy_m',  'precise_accuracy_m INT NULL AFTER precise_lng');
    $add('precise_captured_at', 'precise_captured_at DATETIME NULL AFTER precise_accuracy_m');

    // Indexes — idempotent via SHOW INDEX check (ADD INDEX has no IF NOT EXISTS in MySQL 5.7/8).
    $idx = $pdo->query("SHOW INDEX FROM user_sessions")->fetchAll(PDO::FETCH_COLUMN, 2); // Key_name column
    $addIndex = function (string $name, string $ddl) use ($pdo, $idx) {
        if (in_array($name, $idx, true)) {
            echo "  · index $name already exists — skipped.\n";
            return;
        }
        $pdo->exec("ALTER TABLE user_sessions ADD $ddl");
        echo "  + index $name added.\n";
    };
    $addIndex('idx_open_sessions',   'INDEX idx_open_sessions (user_id, logout_at)');
    $addIndex('idx_last_seen',       'INDEX idx_last_seen (last_seen_at)');
    $addIndex('idx_php_session_id',  'INDEX idx_php_session_id (php_session_id)');

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
