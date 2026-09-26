<?php
/**
 * migrations/tenant/2026_09_26_mm_float_and_commission_tables.php
 *
 * Phase 0 — Mobile Money module: float and commission tables.
 * Creates: mm_float_movements, mm_commissions_received, mm_float_snapshots
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: mm_float_and_commission_tables...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_float_movements` (
            `movement_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `movement_code`     VARCHAR(40)  NOT NULL,
            `till_id`           INT UNSIGNED NOT NULL,
            `movement_type`     ENUM('float_topup','float_withdrawal','opening_balance','adjustment') NOT NULL,
            `movement_date`     DATE NOT NULL,
            `amount`            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `bank_account_id`   INT UNSIGNED NULL DEFAULT NULL,
            `reference_no`      VARCHAR(60)  NOT NULL DEFAULT '',
            `notes`             TEXT NULL DEFAULT NULL,
            `journal_entry_id`  INT UNSIGNED NULL DEFAULT NULL,
            `status`            ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`        INT UNSIGNED NULL DEFAULT NULL,
            `posted_at`         DATETIME NULL DEFAULT NULL,
            `posted_by`         INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`movement_id`),
            UNIQUE KEY `ux_mm_float_movements_code` (`movement_code`),
            KEY `idx_mm_float_till_date` (`till_id`,`movement_date`),
            KEY `idx_mm_float_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_float_movements created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_commissions_received` (
            `credit_id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `network_id`        INT UNSIGNED NOT NULL,
            `period_from`       DATE NOT NULL,
            `period_to`         DATE NOT NULL,
            `amount_received`   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `bank_account_id`   INT UNSIGNED NOT NULL,
            `reference_no`      VARCHAR(60)  NOT NULL DEFAULT '',
            `notes`             TEXT NULL DEFAULT NULL,
            `journal_entry_id`  INT UNSIGNED NULL DEFAULT NULL,
            `status`            ENUM('draft','posted','void') NOT NULL DEFAULT 'draft',
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`        INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`credit_id`),
            KEY `idx_mm_comm_received_network` (`network_id`),
            KEY `idx_mm_comm_received_period` (`period_from`,`period_to`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_commissions_received created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_float_snapshots` (
            `snapshot_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `till_id`       INT UNSIGNED NOT NULL,
            `snapshot_at`   DATETIME NOT NULL,
            `float_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `cash_balance`  DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (`snapshot_id`),
            KEY `idx_mm_snapshot_till_at` (`till_id`,`snapshot_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_float_snapshots created.\n";

    echo "Migration complete: mm_float_and_commission_tables.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
