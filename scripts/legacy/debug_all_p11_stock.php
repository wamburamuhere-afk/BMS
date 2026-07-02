<?php
require_once 'roots.php';
global $pdo;

echo "All stock records for Product 11:\n";
$stmt = $pdo->prepare("SELECT ps.*, w.warehouse_name FROM product_stocks ps LEFT JOIN warehouses w ON ps.warehouse_id = w.warehouse_id WHERE ps.product_id = 11");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
