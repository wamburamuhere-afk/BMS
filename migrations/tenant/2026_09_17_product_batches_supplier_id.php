<?php
/**
 * migrations/tenant/2026_09_17_product_batches_supplier_id.php
 *
 * Supplier Access / Quick Restock optional supplier (2026-09-17 request):
 * product_batches has no supplier_id today, so there is no way to record
 * which supplier a batch was restocked from. Adds a nullable supplier_id —
 * deliberately optional (not required, no FK-enforced restock flow), since
 * the product owner explicitly wants this field to stay optional on the
 * Restock form. NULL simply means "no supplier recorded for this batch",
 * same as before this column existed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: add product_batches.supplier_id...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'product_batches'")->fetch();
    if (!$table) {
        echo "  product_batches table not found — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $col = $pdo->query("SHOW COLUMNS FROM product_batches LIKE 'supplier_id'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        echo "  product_batches.supplier_id already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE product_batches ADD COLUMN supplier_id INT NULL AFTER warehouse_id");
        echo "  + Added product_batches.supplier_id (nullable).\n";
    }

    $idx = $pdo->query("SHOW INDEX FROM product_batches WHERE Key_name = 'idx_product_batches_supplier_id'")->fetch();
    if ($idx) {
        echo "  index idx_product_batches_supplier_id already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE product_batches ADD INDEX idx_product_batches_supplier_id (supplier_id)");
        echo "  + Added index idx_product_batches_supplier_id.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
