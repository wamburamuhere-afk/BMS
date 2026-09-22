<?php
/**
 * migrations/tenant/2026_09_22_products_supplier_id.php
 *
 * Bug fix: create_product.php and update_product.php always include supplier_id
 * in INSERT/UPDATE, but tenants provisioned before this column was added to the
 * schema template are missing it, causing SQLSTATE[42S22] "Unknown column
 * 'supplier_id' in 'field list'" on product creation.
 *
 * This adds products.supplier_id (nullable INT with FK-style index, no hard FK
 * constraint so it is safe for tenants without a suppliers table yet) to every
 * tenant that is missing it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;

echo "Starting tenant migration: products.supplier_id (bug fix)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'supplier_id'")->fetch();
    if ($col) {
        echo "  · products.supplier_id already present — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // Add after current_stock to match the canonical schema order
    $pdo->exec("ALTER TABLE products ADD COLUMN supplier_id INT NULL AFTER current_stock");
    echo "  + products.supplier_id added (nullable INT).\n";

    // Index for supplier-filtered product queries
    $idx = $pdo->query("SHOW INDEX FROM products WHERE Key_name = 'idx_products_supplier_id'")->fetch();
    if (!$idx) {
        $pdo->exec("ALTER TABLE products ADD INDEX idx_products_supplier_id (supplier_id)");
        echo "  + index idx_products_supplier_id added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
