<?php
/**
 * migrations/2026_09_11_pos_product_serials_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_11_pos_product_serials.php
 * (Phase 26 — pos_upgrade_plan.md §9) onto the LEGACY / non-tenant database.
 * See migrations/2026_09_10_pos_product_professional_fields_legacy_db.php
 * for why this mirror exists: core/tenant_migration_runner.php only ever
 * touches databases registered in the `tenants` control table, so a host
 * running in single-tenant mode needs its own copy of every tenant migration.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS product serials on the legacy database (Phase 26)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'track_serials'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode_symbology'")->fetch();
        $position = $anchor ? " AFTER barcode_symbology" : "";
        $pdo->exec("ALTER TABLE products ADD COLUMN track_serials TINYINT(1) NOT NULL DEFAULT 0$position");
        echo "  + products.track_serials added" . ($position ? "" : " (anchor column 'barcode_symbology' not found — appended at end instead)") . ".\n";
    } else {
        echo "  · products.track_serials already present.\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_serials` (
            `serial_id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `warehouse_id` INT NOT NULL,
            `serial_number` VARCHAR(191) NOT NULL,
            `status` ENUM('in_stock','sold','returned','damaged') NOT NULL DEFAULT 'in_stock',
            `sale_item_id` INT DEFAULT NULL,
            `receipt_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`serial_id`),
            UNIQUE KEY `uq_product_serial` (`product_id`, `serial_number`),
            KEY `idx_product_wh_status` (`product_id`, `warehouse_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table product_serials ready.\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pos_sale_item_serials` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `sale_item_id` INT NOT NULL,
            `serial_id` INT NOT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_sale_item` (`sale_item_id`),
            KEY `idx_serial` (`serial_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table pos_sale_item_serials ready.\n";

    $col = $pdo->query("SHOW COLUMNS FROM receipt_items LIKE 'serial_numbers'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM receipt_items LIKE 'expiry_date'")->fetch();
        $position = $anchor ? " AFTER expiry_date" : "";
        $pdo->exec("ALTER TABLE receipt_items ADD COLUMN serial_numbers TEXT NULL$position");
        echo "  + receipt_items.serial_numbers added" . ($position ? "" : " (anchor column 'expiry_date' not found — appended at end instead)") . ".\n";
    } else {
        echo "  · receipt_items.serial_numbers already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM stock_movements LIKE 'reference_type'")->fetch();
    if ($col && strpos($col['Type'], "'pos_void'") === false) {
        $pdo->exec("ALTER TABLE stock_movements MODIFY COLUMN reference_type ENUM('purchase_order','sales_order','pos_sale','invoice','stock_adjustment','stock_transfer','return','production_order','manual','pos_void','pos_return') DEFAULT NULL");
        echo "  + stock_movements.reference_type ENUM extended with 'pos_void','pos_return'.\n";
    } else {
        echo "  · stock_movements.reference_type already includes 'pos_void'/'pos_return'.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
