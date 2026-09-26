<?php
/**
 * migrations/2026_09_26_mm_reconciliation_tables_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_09_26_mm_reconciliation_tables.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

if (!(bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
    echo "Legacy DB has no users table — skipping mm_reconciliation_tables migration.\n";
    exit(0);
}

echo "Starting legacy migration: mm_reconciliation_tables...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_reconciliations` (
            `recon_id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `recon_code`        VARCHAR(40)  NOT NULL,
            `till_id`           INT UNSIGNED NOT NULL,
            `recon_date`        DATE NOT NULL,
            `opening_cash`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `opening_float`     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `computed_cash`     DECIMAL(15,2) NULL DEFAULT NULL,
            `computed_float`    DECIMAL(15,2) NULL DEFAULT NULL,
            `actual_cash`       DECIMAL(15,2) NULL DEFAULT NULL,
            `actual_float`      DECIMAL(15,2) NULL DEFAULT NULL,
            `cash_variance`     DECIMAL(15,2) NULL DEFAULT NULL,
            `float_variance`    DECIMAL(15,2) NULL DEFAULT NULL,
            `status`            ENUM('open','resolved','disputed','closed') NOT NULL DEFAULT 'open',
            `resolved_notes`    TEXT NULL DEFAULT NULL,
            `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by`        INT UNSIGNED NULL DEFAULT NULL,
            `closed_at`         DATETIME NULL DEFAULT NULL,
            `closed_by`         INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`recon_id`),
            UNIQUE KEY `ux_mm_recon_code` (`recon_code`),
            UNIQUE KEY `ux_mm_recon_till_date` (`till_id`,`recon_date`),
            KEY `idx_mm_recon_date` (`recon_date`),
            KEY `idx_mm_recon_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_reconciliations created.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mm_recon_items` (
            `item_id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `recon_id`          INT UNSIGNED NOT NULL,
            `mm_txn_id`         BIGINT UNSIGNED NULL DEFAULT NULL,
            `statement_ref`     VARCHAR(60)  NULL DEFAULT NULL,
            `statement_amount`  DECIMAL(15,2) NULL DEFAULT NULL,
            `match_status`      ENUM('matched','unmatched_book','unmatched_stmt','disputed') NOT NULL DEFAULT 'unmatched_book',
            `notes`             TEXT NULL DEFAULT NULL,
            PRIMARY KEY (`item_id`),
            KEY `idx_mm_recon_items_recon` (`recon_id`),
            KEY `idx_mm_recon_items_txn` (`mm_txn_id`),
            KEY `idx_mm_recon_items_status` (`match_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + mm_recon_items created.\n";

    echo "Migration complete: mm_reconciliation_tables (legacy).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
