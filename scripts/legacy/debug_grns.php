<?php
require_once 'roots.php';
global $pdo;

echo "Recent purchase_receipts (GRNs):\n";
$stmt = $pdo->query("SELECT receipt_id, receipt_number, warehouse_id, supplier_id, status FROM purchase_receipts ORDER BY receipt_id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nWarehouses:\n";
$stmt = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nSuppliers:\n";
$stmt = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
