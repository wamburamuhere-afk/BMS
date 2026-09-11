<?php
/**
 * migrations/tenant/2026_09_11_pos_product_serials.php
 *
 * Phase 26 (pos_upgrade_plan.md §9) — serial/IMEI-level stock tracking.
 * Modeled directly on Phase 17's product_batches/pos_sale_item_batches
 * shape, generalized to single units (qty always 1) instead of a
 * decrementing pool. products.track_serials defaults to 0 so every
 * existing product keeps behaving exactly as it did before this phase.
 *
 * Bundled fix (relocated from the removed Repair phase, pos_upgrade_plan.md
 * §8 Phase 27 note): stock_movements.reference_type is missing 'pos_void'/
 * 'pos_return', which void_sale.php and create_return.php have been writing
 * since Phase 1/7 — under this server's non-strict sql_mode those values
 * silently coerce to '' instead of erroring. Fixed here because this
 * migration already touches the same consume/reverse stock-movement code
 * path.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS product serials (Phase 26)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'track_serials'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN track_serials TINYINT(1) NOT NULL DEFAULT 0 AFTER barcode_symbology");
        echo "  + products.track_serials added.\n";
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

    // receipt_items.serial_numbers — raw, comma/newline-separated serials
    // entered at GRN creation for a track_serials=1 line; parsed into
    // individual product_serials rows on approval (Phase 17's exact
    // "stock arrives on approval, not creation" rule).
    $col = $pdo->query("SHOW COLUMNS FROM receipt_items LIKE 'serial_numbers'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE receipt_items ADD COLUMN serial_numbers TEXT NULL AFTER expiry_date");
        echo "  + receipt_items.serial_numbers added.\n";
    } else {
        echo "  · receipt_items.serial_numbers already present.\n";
    }

    // Bundled fix — add the two missing reference_type values.
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
