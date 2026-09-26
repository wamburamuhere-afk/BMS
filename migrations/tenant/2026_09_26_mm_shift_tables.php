<?php
/**
 * migrations/tenant/2026_09_26_mm_shift_tables.php
 *
 * Phase 0 — Mobile Money module: shift + user-grant tables.
 * Creates: mm_shifts, mm_user_agent_grants
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mm_shift_tables...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_shifts` (
            `shift_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `shift_code`        VARCHAR(40)  NOT NULL,
            `till_id`           INT UNSIGNED NOT NULL,
            `teller_user_id`    INT UNSIGNED NOT NULL,
            `opened_at`         DATETIME NOT NULL,
            `closed_at`         DATETIME NULL DEFAULT NULL,
            `opening_cash`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `opening_float`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `closing_cash`      DECIMAL(15,2) NULL DEFAULT NULL,
            `closing_float`     DECIMAL(15,2) NULL DEFAULT NULL,
            `expected_cash`     DECIMAL(15,2) NULL DEFAULT NULL,
            `expected_float`    DECIMAL(15,2) NULL DEFAULT NULL,
            `cash_variance`     DECIMAL(15,2) NULL DEFAULT NULL,
            `float_variance`    DECIMAL(15,2) NULL DEFAULT NULL,
            `status`            ENUM('open','closed','forced_close') NOT NULL DEFAULT 'open',
            `close_notes`       TEXT NULL DEFAULT NULL,
            `closed_by`         INT UNSIGNED NULL DEFAULT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`shift_id`),
            UNIQUE KEY `ux_mm_shifts_code` (`shift_code`),
            KEY `idx_mm_shifts_till` (`till_id`),
            KEY `idx_mm_shifts_teller` (`teller_user_id`),
            KEY `idx_mm_shifts_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_shifts created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_user_agent_grants` (
            `grant_id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`                   INT UNSIGNED NOT NULL,
            `agent_id`                  INT UNSIGNED NOT NULL,
            `till_id`                   INT UNSIGNED NULL DEFAULT NULL,
            `can_open_shift`            TINYINT(1) NOT NULL DEFAULT 1,
            `can_record_transactions`   TINYINT(1) NOT NULL DEFAULT 1,
            `can_close_shift`           TINYINT(1) NOT NULL DEFAULT 1,
            `can_reconcile`             TINYINT(1) NOT NULL DEFAULT 0,
            `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `granted_by`                INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`grant_id`),
            UNIQUE KEY `ux_mm_grants_user_agent_till` (`user_id`,`agent_id`,`till_id`),
            KEY `idx_mm_grants_user` (`user_id`),
            KEY `idx_mm_grants_agent` (`agent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_user_agent_grants created.\n";

    echo "Migration complete: mm_shift_tables.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
