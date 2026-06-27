<?php
// Migration: widen products.product_name from VARCHAR(255) to TEXT
require_once __DIR__ . '/../roots.php';

try {
    // Drop the existing index that prevents TEXT conversion
    $pdo->exec("ALTER TABLE products DROP INDEX idx_product_name");
    // Widen the column to TEXT
    $pdo->exec("ALTER TABLE products MODIFY COLUMN product_name TEXT NOT NULL");
    // Recreate the index with a prefix (TEXT requires a length for BTREE)
    $pdo->exec("ALTER TABLE products ADD INDEX idx_product_name (product_name(255))");
    echo "OK: products.product_name changed to TEXT\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
