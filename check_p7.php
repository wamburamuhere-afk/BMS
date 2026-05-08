<?php
require_once 'roots.php';
require_once 'includes/config.php';
$id = 7;
$stmt = $pdo->prepare("SELECT product_name, stock_quantity, min_stock_level, status FROM products WHERE product_id = ?");
$stmt->execute([$id]);
print_r($stmt->fetch(PDO::FETCH_ASSOC));

echo "\nSTOCK ENTRIES:\n";
$stmt = $pdo->prepare("SELECT * FROM product_stocks WHERE product_id = ?");
$stmt->execute([$id]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
