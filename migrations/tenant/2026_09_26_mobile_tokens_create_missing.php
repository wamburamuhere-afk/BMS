<?php
/**
 * migrations/tenant/2026_09_26_mobile_tokens_create_missing.php
 *
 * Catch-up: creates mobile_tokens on tenant DBs provisioned before the table
 * was added to the schema template. Also fixes expires_at to be nullable on
 * any DB where the table was created with NOT NULL (the original migration had
 * a schema mismatch — the code passes NULL).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mobile_tokens_create_missing...\n";

try {
    // Create table if missing
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mobile_tokens` (
            `token_id`     INT           NOT NULL AUTO_INCREMENT,
            `token`        CHAR(64)      NOT NULL,
            `user_id`      INT           NOT NULL,
            `device_name`  VARCHAR(255)  NOT NULL DEFAULT '',
            `last_used_at` TIMESTAMP     NULL DEFAULT NULL,
            `expires_at`   DATETIME      NULL DEFAULT NULL,
            `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`token_id`),
            UNIQUE KEY `uq_token` (`token`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mobile_tokens table ensured.\n";

    // Fix expires_at to nullable if it was created NOT NULL (schema mismatch in original migration)
    $col = $pdo->query("SHOW COLUMNS FROM `mobile_tokens` LIKE 'expires_at'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos((string)($col['Null'] ?? ''), 'NO') !== false) {
        $pdo->exec("ALTER TABLE `mobile_tokens` MODIFY COLUMN `expires_at` DATETIME NULL DEFAULT NULL");
        echo "  + expires_at changed to nullable.\n";
    }

    // Drop the index on expires_at if it exists (not needed for non-expiring tokens)
    $idx = $pdo->query("SHOW INDEX FROM `mobile_tokens` WHERE Key_name = 'idx_expires_at'")->fetch(PDO::FETCH_ASSOC);
    if ($idx) {
        $pdo->exec("ALTER TABLE `mobile_tokens` DROP INDEX `idx_expires_at`");
        echo "  + idx_expires_at index removed.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
