<?php
/**
 * migrations/tenant/2026_09_08_pos_unit_conversions.php
 *
 * Phase 15 (pos_upgrade_plan.md §8) — unit conversion at the register
 * (carton/ream/dozen <-> piece). products.unit stays the single source of
 * truth for the BASE stock unit (free-text today — confirmed by reading
 * app/bms/product/product_edit.php, not linked to product_units by FK) —
 * product_stocks.stock_quantity keeps meaning base units unchanged, so
 * nothing downstream breaks. A conversion row is an ADDITIONAL selling unit
 * for a product, with a multiplier back to the base unit and an optional
 * per-selling-unit price override.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS unit conversions (Phase 15)...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_unit_conversions` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `unit_label` VARCHAR(50) NOT NULL,
            `base_unit_multiplier` DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
            `unit_price_override` DECIMAL(15,2) DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_product_unit_label` (`product_id`, `unit_label`),
            KEY `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table product_unit_conversions ready.\n";

    // Informational columns on the sale line: what the cashier actually rang
    // up ("2 Carton"), distinct from pos_sale_items.quantity/unit_price which
    // stay in BASE units (unchanged meaning — stock/pricing math untouched).
    foreach ([
        'sold_unit_label'    => "VARCHAR(50) DEFAULT NULL",
        'sold_unit_quantity' => "DECIMAL(10,3) DEFAULT NULL",
    ] as $col => $def) {
        $exists = $pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE " . $pdo->quote($col))->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE pos_sale_items ADD COLUMN `$col` $def");
            echo "  + pos_sale_items.$col added.\n";
        } else {
            echo "  · pos_sale_items.$col already present.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
