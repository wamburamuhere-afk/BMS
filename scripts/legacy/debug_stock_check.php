<?php
require_once 'roots.php';
global $pdo;

// Check a specific return that was marked completed
echo "Recent Purchase Returns:\n";
$stmt = $pdo->query("SELECT purchase_return_id, return_number, warehouse_id, status, stock_updated FROM purchase_returns ORDER BY purchase_return_id DESC LIMIT 5");
$returns = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($returns);

if (!empty($returns)) {
    $return_id = $returns[0]['purchase_return_id'];
    echo "\nItems for Return ID $return_id:\n";
    $stmt = $pdo->prepare("SELECT product_id, quantity FROM purchase_return_items WHERE purchase_return_id = ?");
    $stmt->execute([$return_id]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
}

echo "\nSample Product Stocks:\n";
$stmt = $pdo->query("SELECT product_id, warehouse_id, stock_quantity FROM product_stocks LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
