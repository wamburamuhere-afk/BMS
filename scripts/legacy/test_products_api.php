<?php
// Test script to verify products API
require_once __DIR__ . '/roots.php';

global $pdo;

echo "=== TESTING PRODUCTS API ===\n\n";

// Test 1: Check active products
$stmt = $pdo->query("SELECT COUNT(*) as total FROM products WHERE status = 'active'");
$count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
echo "1. Active products in database: $count\n\n";

// Test 2: Run the actual API query
$sql = "SELECT 
            p.product_id,
            p.product_name,
            p.sku,
            p.selling_price,
            p.stock_quantity,
            p.is_service
        FROM products p
        WHERE p.status = 'active'
        ORDER BY p.product_name ASC
        LIMIT 10";

$stmt = $pdo->query($sql);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "2. Products from query: " . count($products) . "\n\n";

if (count($products) > 0) {
    echo "3. Sample products:\n";
    foreach ($products as $p) {
        echo "   - {$p['product_name']} (ID: {$p['product_id']}, Price: {$p['selling_price']}, Stock: {$p['stock_quantity']})\n";
    }
} else {
    echo "3. NO PRODUCTS FOUND!\n";
}

echo "\n=== END TEST ===\n";
?>
