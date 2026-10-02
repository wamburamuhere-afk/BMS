<?php
/**
 * Migration: 2026_10_02_mobile_idempotency_keys
 *
 * Generic replay guard for mobile wrappers around shared web endpoints
 * (core/mobile_auth.php mobileIdempotent()). One row per client_uuid; the
 * stored response is returned verbatim on a retry. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mobile_idempotency_keys...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mobile_idempotency_keys` (
            `client_uuid`   CHAR(36)     NOT NULL,
            `endpoint`      VARCHAR(100) NOT NULL,
            `user_id`       INT          NOT NULL,
            `http_code`     SMALLINT     NULL DEFAULT NULL,
            `response_body` MEDIUMTEXT   NULL,
            `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`client_uuid`),
            KEY `idx_endpoint` (`endpoint`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mobile_idempotency_keys ensured.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
