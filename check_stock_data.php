<?php
require_once 'roots.php';
global $pdo;
echo "Total in product_stocks: " . $pdo->query("SELECT COUNT(*) FROM product_stocks")->fetchColumn() . "\n";
echo "By Warehouse:\n";
print_r($pdo->query("SELECT warehouse_id, COUNT(*) as count FROM product_stocks GROUP BY warehouse_id")->fetchAll(PDO::FETCH_ASSOC));
echo "Total Products: " . $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() . "\n";
