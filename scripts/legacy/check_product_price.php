<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'c:/wamp64/www/bms/includes/config.php';

echo "=== Checking Products Table ===\n";

try {
    // 1. Check Table Structure
    $stmt = $pdo->query("DESCRIBE products");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Columns: " . implode(', ', $columns) . "\n\n";
    
    if (in_array('selling_price', $columns)) {
        echo "✓ 'selling_price' column exists.\n";
    } else {
        echo "✗ 'selling_price' column MISSING!\n";
    }

    // 2. Check Data
    $stmt = $pdo->query("SELECT product_id, product_name, selling_price FROM products LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nSample Data:\n";
    foreach ($rows as $row) {
        echo "[ID: {$row['product_id']}] {$row['product_name']} -> Price: " . ($row['selling_price'] ?? 'NULL') . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
