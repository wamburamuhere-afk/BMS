<?php
require_once 'roots.php';
global $pdo;
$res = $pdo->query("SELECT * FROM purchase_order_items ORDER BY item_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($res as $row) {
    echo "ID: {$row['item_id']} | PO_ID: {$row['purchase_order_id']} | PID: {$row['product_id']} | Name: {$row['item_name']} | OID: {$row['order_item_id']}\n";
}
