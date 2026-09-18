<?php
/**
 * migrations/2026_09_17_product_batches_supplier_id_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_17_product_batches_supplier_id.php onto
 * the LEGACY / non-tenant database. See
 * 2026_09_08_pos_network_printer_legacy_db.php for why this pairing exists.
 *
 * Degrades instead of failing: if `product_batches` doesn't exist on this
 * database, that's outside this migration's job — log it and exit 0 rather
 * than blocking every other host's deploy over it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add product_batches.supplier_id on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'product_batches'")->fetch();
    if (!$table) {
        echo "  · product_batches does not exist on this database — nothing to do. Skipping.\n";
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
