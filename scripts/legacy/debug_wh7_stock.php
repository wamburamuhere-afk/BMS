<?php
require_once 'roots.php';
global $pdo;

echo "Product 11 in Warehouse 7:\n";
$stmt = $pdo->prepare("SELECT * FROM product_stocks WHERE product_id = 11 AND warehouse_id = 7");
$stmt->execute();
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\nProduct 15 in Warehouse 7:\n";
$stmt = $pdo->prepare("SELECT * FROM product_stocks WHERE product_id = 15 AND warehouse_id = 7");
$stmt->execute();
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\nCheck if any stocks exist for Warehouse 7:\n";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM product_stocks WHERE warehouse_id = 7");
$stmt->execute();
echo "Count: " . $stmt->fetchColumn() . "\n";
