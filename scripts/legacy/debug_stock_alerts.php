<?php
require_once 'includes/config.php';
$stmt = $pdo->prepare("
    SELECT 
        p.product_id,
        p.product_name,
        p.min_stock_level,
        COALESCE(s.available_stock, 0) as available_stock
    FROM products p 
    LEFT JOIN (
        SELECT product_id, 
               SUM(stock_quantity - reserved_quantity) as available_stock
        FROM product_stocks
        GROUP BY product_id
    ) s ON p.product_id = s.product_id
    WHERE p.status = 'active'
");
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Products and Stock Levels:\n";
foreach ($products as $p) {
    $status = ($p['available_stock'] <= $p['min_stock_level'] && $p['min_stock_level'] > 0) ? "LOW STOCK" : "OK";
    echo "- {$p['product_name']}: Stock={$p['available_stock']}, Min={$p['min_stock_level']} [$status]\n";
}
