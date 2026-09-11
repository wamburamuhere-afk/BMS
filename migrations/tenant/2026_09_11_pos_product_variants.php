<?php
/**
 * migrations/tenant/2026_09_11_pos_product_variants.php
 *
 * Phase 31 (pos_upgrade_plan.md §8) — Product Variants (size/color matrix).
 *
 * A variant is a normal row in `products` — not a parallel variants table.
 * Two new nullable columns: `parent_product_id` (FK to products.product_id)
 * and `variant_attributes` (JSON, e.g. {"Size":"L","Color":"Red"}). Because
 * every downstream system already keys off product_id (batches, combos,
 * price groups, per-warehouse stock, serials, GL posting), a variant works
 * with all of them with ZERO changes — the child row IS a product as far as
 * everything else in BMS is concerned.
 *
 * Both columns are nullable/NULL-default: every existing product is
 * completely unaffected until an admin explicitly generates variants for one.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS product variants (Phase 31)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'parent_product_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN parent_product_id INT NULL DEFAULT NULL AFTER kitchen_station_id");
        $pdo->exec("ALTER TABLE products ADD KEY idx_products_parent (parent_product_id)");
        echo "  + products.parent_product_id added (+ index).\n";
    } else {
        echo "  · products.parent_product_id already present.\n";
    }

    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'variant_attributes'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE products ADD COLUMN variant_attributes JSON NULL DEFAULT NULL AFTER parent_product_id");
        echo "  + products.variant_attributes added.\n";
    } else {
        echo "  · products.variant_attributes already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
