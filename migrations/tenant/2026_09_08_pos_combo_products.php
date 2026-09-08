<?php
/**
 * migrations/tenant/2026_09_08_pos_combo_products.php
 *
 * Phase 23 (pos_upgrade_plan.md §8) — combo/bundle products. Reuses the
 * existing `product_assembly_components` table (parent_product_id,
 * component_product_id, unit, qty_per_unit) — already a general-purpose
 * "product X is made of N units of product Y" relationship, currently used
 * for service cost-breakdown/NIP material lists (confirmed by reading
 * api/get_service_components.php before reusing it: parent_product_id
 * already references products.product_id, qty_per_unit already means
 * "how many of this component per one unit of the parent" — exactly the
 * combo shape needed). No collision risk: this migration only adds a new
 * `is_combo` flag on `products`; existing service/NIP rows are untouched
 * and POS combo handling only ever triggers on products explicitly marked
 * is_combo=1 through the new Phase 23 UI.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: POS combo products (Phase 23)...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_combo'")->fetch();
    if (!$exists) {
        $pdo->exec("ALTER TABLE products ADD COLUMN is_combo TINYINT(1) NOT NULL DEFAULT 0 AFTER is_service");
        echo "  + products.is_combo added.\n";
    } else {
        echo "  · products.is_combo already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
