<?php
/**
 * migrations/tenant/2026_09_11_pos_sales_targets.php
 *
 * Phase 29 (pos_upgrade_plan.md §9) — POS Dashboard Intelligence: Sales
 * Targets vs Actual. New table `pos_sales_targets`.
 *
 * warehouse_id/user_id use `0` (never NULL) as the "all warehouses"/"all
 * cashiers" sentinel — MySQL treats NULL as distinct in a UNIQUE key, which
 * would silently allow duplicate company-wide target rows for the same
 * month; `0` (no real warehouse_id/user_id is ever 0) avoids that pitfall
 * with no application-level dedupe logic needed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS sales targets (Phase 29)...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pos_sales_targets` (
            `target_id` INT NOT NULL AUTO_INCREMENT,
            `warehouse_id` INT NOT NULL DEFAULT 0,
            `user_id` INT NOT NULL DEFAULT 0,
            `period_month` DATE NOT NULL,
            `target_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`target_id`),
            UNIQUE KEY `uq_pos_target_scope_month` (`warehouse_id`, `user_id`, `period_month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table pos_sales_targets ready.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
