<?php
/**
 * migrations/2026_09_11_pos_product_variants_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_11_pos_product_variants.php (Phase 31 —
 * pos_upgrade_plan.md §8) onto the LEGACY / non-tenant database. See
 * 2026_09_08_pos_price_groups_legacy_db.php for why this exists, and
 * 2026_09_08_pos_network_printer_legacy_db.php for why the anchor-missing
 * degrade-instead-of-fail approach is used here too.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: POS product variants on the legacy database (Phase 31)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'parent_product_id'")->fetch();
    if ($col) {
        echo "  · products.parent_product_id already present.\n";
    } else {
        $anchor = $pdo->query("SHOW COLUMNS FROM products LIKE 'kitchen_station_id'")->fetch();
        $position = $anchor ? " AFTER kitchen_station_id" : "";
        $pdo->exec("ALTER TABLE products ADD COLUMN parent_product_id INT NULL DEFAULT NULL$position");
        $pdo->exec("ALTER TABLE products ADD KEY idx_products_parent (parent_product_id)");
        echo "  + products.parent_product_id added (+ index)" . ($position ? "" : " (anchor column 'kitchen_station_id' not found — appended at end instead)") . ".\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'variant_attributes'")->fetch();
    if ($col) {
        echo "  · products.variant_attributes already present.\n";
    } else {
        $pdo->exec("ALTER TABLE products ADD COLUMN variant_attributes JSON NULL DEFAULT NULL AFTER parent_product_id");
        echo "  + products.variant_attributes added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
