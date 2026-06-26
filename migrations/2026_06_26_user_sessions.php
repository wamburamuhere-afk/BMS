<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: user_sessions (login/logout + duration tracking)...\n";

/*
 * Audit-grade session ledger: one row per login. login_at is stamped on sign-in;
 * logout_at + duration_seconds are filled on sign-out. Open rows (no logout) are
 * sessions the user never closed (browser closed / timed out) — kept honest, not
 * faked. Feeds the "how long did the user stay in the system" view on the
 * Activity Logs page and gives auditors a clean who/when/how-long trail.
 */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            user_id          INT          NOT NULL,
            login_at         DATETIME     NOT NULL,
            logout_at        DATETIME     NULL,
            duration_seconds INT          NULL,
            logout_type      VARCHAR(20)  NULL,   -- 'manual' | 'timeout' | NULL(open)
            ip_address       VARCHAR(45)  NULL,
            user_agent       VARCHAR(255) NULL,
            created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user      (user_id),
            KEY idx_login_at  (login_at),
            KEY idx_user_login (user_id, login_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "user_sessions table ready.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
