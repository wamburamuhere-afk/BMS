<?php
require_once 'roots.php';
global $pdo;
$res = $pdo->query("SELECT purchase_order_id, order_number, status FROM purchase_orders ORDER BY purchase_order_id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($res as $row) {
    echo "ID: {$row['purchase_order_id']} | No: {$row['order_number']} | Status: [{$row['status']}]\n";
}
