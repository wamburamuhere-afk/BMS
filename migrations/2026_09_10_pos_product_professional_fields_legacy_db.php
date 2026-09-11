<?php
/**
 * migrations/2026_09_10_pos_product_professional_fields_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_10_pos_product_professional_fields.php
 * (Phase 25 — pos_upgrade_plan.md §9) onto the LEGACY / non-tenant database.
 * See migrations/2026_09_08_pos_price_groups_legacy_db.php for why this
 * mirror exists: core/tenant_migration_runner.php only ever touches
 * databases registered in the `tenants` control table, so a host running in
 * single-tenant mode needs its own copy of every tenant migration.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS product professional fields on the legacy database (Phase 25)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'warranty_unit'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM products LIKE 'warranty_period'")->fetch();
        $position = $anchor ? " AFTER warranty_period" : "";
        $pdo->exec("ALTER TABLE products ADD COLUMN warranty_unit ENUM('days','months','years') NULL$position");
        echo "  + products.warranty_unit added" . ($position ? "" : " (anchor column 'warranty_period' not found — appended at end instead)") . ".\n";
    } else {
        echo "  · products.warranty_unit already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'guarantee_period'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN guarantee_period INT NULL");
        echo "  + products.guarantee_period added.\n";
    } else {
        echo "  · products.guarantee_period already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'guarantee_unit'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN guarantee_unit ENUM('days','months','years') NULL");
        echo "  + products.guarantee_unit added.\n";
    } else {
        echo "  · products.guarantee_unit already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode_symbology'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM products LIKE 'barcode'")->fetch();
        $position = $anchor ? " AFTER barcode" : "";
        $pdo->exec("ALTER TABLE products ADD COLUMN barcode_symbology ENUM('CODE128','CODE39','UPC_A','UPC_E','EAN_8','EAN_13') NOT NULL DEFAULT 'CODE128'$position");
        echo "  + products.barcode_symbology added" . ($position ? "" : " (anchor column 'barcode' not found — appended at end instead)") . ".\n";
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

    $col = $pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE 'promo_original_price'")->fetch();
    if (!$col) {
        $anchor = $pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE 'unit_price'")->fetch();
        $position = $anchor ? " AFTER unit_price" : "";
        $pdo->exec("ALTER TABLE pos_sale_items ADD COLUMN promo_original_price DECIMAL(15,2) NULL$position");
        echo "  + pos_sale_items.promo_original_price added" . ($position ? "" : " (anchor column 'unit_price' not found — appended at end instead)") . ".\n";
    } else {
        echo "  · pos_sale_items.promo_original_price already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
