<?php
require_once 'roots.php';
global $pdo;

echo "Product 11 Details:\n";
$stmt = $pdo->prepare("SELECT product_id, product_name, current_stock, stock_quantity FROM products WHERE product_id = 11");
$stmt->execute();
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\nProduct 15 Details:\n";
$stmt = $pdo->prepare("SELECT product_id, product_name, current_stock, stock_quantity FROM products WHERE product_id = 15");
$stmt->execute();
print_r($stmt->fetch(PDO::FETCH_ASSOC));
