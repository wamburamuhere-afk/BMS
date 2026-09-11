<?php
/**
 * migrations/2026_09_11_pos_sales_targets_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_11_pos_sales_targets.php
 * (Phase 29 — pos_upgrade_plan.md §9) onto the LEGACY / non-tenant database.
 * See migrations/2026_09_10_pos_product_professional_fields_legacy_db.php
 * for why this mirror exists: core/tenant_migration_runner.php only ever
 * touches databases registered in the `tenants` control table, so a host
 * running in single-tenant mode needs its own copy of every tenant migration.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS sales targets on the legacy database (Phase 29)...\n";

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
