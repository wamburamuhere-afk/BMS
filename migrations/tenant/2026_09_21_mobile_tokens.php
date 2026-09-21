<?php
/**
 * migrations/tenant/2026_09_21_mobile_tokens.php
 *
 * Phase 1 (app.md) — mobile app bearer-token authentication. Each Flutter
 * session receives one opaque 64-hex-char token that replaces browser cookies.
 * The token is stored here; core/mobile_auth.php validates it on every request.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mobile_tokens (Phase 1)...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mobile_tokens` (
            `token_id`     INT           NOT NULL AUTO_INCREMENT,
            `token`        CHAR(64)      NOT NULL,
            `user_id`      INT           NOT NULL,
            `device_name`  VARCHAR(255)  NOT NULL DEFAULT '',
            `last_used_at` TIMESTAMP     NULL DEFAULT NULL,
            `expires_at`   DATETIME      NOT NULL,
            `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`token_id`),
            UNIQUE KEY `uq_token` (`token`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table mobile_tokens ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
