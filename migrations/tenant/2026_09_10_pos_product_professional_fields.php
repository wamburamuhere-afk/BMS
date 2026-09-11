<?php
/**
 * migrations/tenant/2026_09_10_pos_product_professional_fields.php
 *
 * Phase 25 (pos_upgrade_plan.md §9) — product professional fields: a proper
 * unit for the existing warranty_period column (previously ambiguous —
 * days vs months vs years), a distinct guarantee concept, a barcode
 * symbology tag for the existing single barcode column, and time-bound
 * promotional pricing (product_promotions) resolved ahead of Phase 14's
 * price-group tiers in core/pos_price_groups.php::resolveGroupPrices().
 * Every column is additive/nullable with a safe default — zero behaviour
 * change for any existing product until someone explicitly sets a new field.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS product professional fields (Phase 25)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'warranty_unit'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN warranty_unit ENUM('days','months','years') NULL AFTER warranty_period");
        echo "  + products.warranty_unit added.\n";
    } else {
        echo "  · products.warranty_unit already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'guarantee_period'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN guarantee_period INT NULL AFTER warranty_unit");
        echo "  + products.guarantee_period added.\n";
    } else {
        echo "  · products.guarantee_period already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'guarantee_unit'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN guarantee_unit ENUM('days','months','years') NULL AFTER guarantee_period");
        echo "  + products.guarantee_unit added.\n";
    } else {
        echo "  · products.guarantee_unit already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode_symbology'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN barcode_symbology ENUM('CODE128','CODE39','UPC_A','UPC_E','EAN_8','EAN_13') NOT NULL DEFAULT 'CODE128' AFTER barcode");
        echo "  + products.barcode_symbology added.\n";
    } else {
        echo "  · products.barcode_symbology already present.\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `product_promotions` (
            `promo_id` INT NOT NULL AUTO_INCREMENT,
            `product_id` INT NOT NULL,
            `price` DECIMAL(15,2) NOT NULL,
            `starts_at` DATETIME NOT NULL,
            `ends_at` DATETIME NOT NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`promo_id`),
            KEY `idx_product_window` (`product_id`, `status`, `starts_at`, `ends_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  + table product_promotions ready.\n";

    // Cosmetic "was / now" strikethrough on the printed receipt — records the
    // plain catalog selling_price a line would have had without an active
    // promo, set only when a promo actually applied at sale time.
    $col = $pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE 'promo_original_price'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE pos_sale_items ADD COLUMN promo_original_price DECIMAL(15,2) NULL AFTER unit_price");
        echo "  + pos_sale_items.promo_original_price added.\n";
    } else {
        echo "  · pos_sale_items.promo_original_price already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
