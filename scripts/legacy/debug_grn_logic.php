<?php
require_once 'roots.php';
global $pdo;

echo "--- PURCHASE ORDERS STATUS ---\n";
$pos = $pdo->query("SELECT po.purchase_order_id, po.order_number, po.status, s.supplier_name 
                    FROM purchase_orders po 
                    JOIN suppliers s ON po.supplier_id = s.supplier_id 
                    LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
foreach ($pos as $po) {
    echo "ID: {$po['purchase_order_id']} | No: {$po['order_number']} | Status: {$po['status']} | Supplier: {$po['supplier_name']}\n";
}

echo "\n--- PENDING ITEMS CHECK ---\n";
$sql = "
    SELECT s.supplier_name, po.order_number, 
           SUM(poi.quantity) as ordered, 
           SUM(IFNULL(pri.received_qty, 0)) as received
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.supplier_id
    JOIN purchase_order_items poi ON po.purchase_order_id = poi.purchase_order_id
    LEFT JOIN (
        SELECT purchase_order_item_id, SUM(quantity_received) as received_qty
        FROM receipt_items
        GROUP BY purchase_order_item_id
    ) pri ON poi.order_item_id = pri.purchase_order_item_id
    WHERE po.status IN ('ordered', 'partially_received')
    GROUP BY po.purchase_order_id
";
$data = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
foreach ($data as $row) {
    $pending = $row['ordered'] - $row['received'];
    echo "Supplier: {$row['supplier_name']} | Order: {$row['order_number']} | Pending: $pending\n";
}
