<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "--- Recent Purchase Orders ---\n";
$stmt = $pdo->query("
    SELECT po.purchase_order_id, po.order_number, po.supplier_id, po.warehouse_id, po.project_id, po.status, s.supplier_name, s.status as supplier_status 
    FROM purchase_orders po 
    JOIN suppliers s ON po.supplier_id = s.supplier_id 
    ORDER BY po.purchase_order_id DESC
");
$pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($pos as $po) {
    echo "ID: {$po['purchase_order_id']} | #{$po['order_number']} | Status: {$po['status']} | Wh: {$po['warehouse_id']} | Proj: {$po['project_id']} | Supplier: {$po['supplier_name']} ({$po['supplier_status']})\n";
}

echo "\n--- All Warehouses ---\n";
$stmt = $pdo->query("SELECT warehouse_id, warehouse_name, project_id FROM warehouses");
$warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($warehouses as $w) {
    echo "ID: {$w['warehouse_id']} | Name: {$w['warehouse_name']} | Proj: {$w['project_id']}\n";
}
?>
