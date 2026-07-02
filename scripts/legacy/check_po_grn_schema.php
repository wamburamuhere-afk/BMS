<?php
require_once 'roots.php';
global $pdo;
$tables = ['purchase_orders', 'purchase_order_items', 'purchase_receipts', 'receipt_items'];
foreach ($tables as $t) {
    echo "TABLE: $t\n";
    try {
        $res = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($res as $row) {
            echo "  {$row['Field']} - {$row['Type']}\n";
        }
    } catch (Exception $e) { echo "  Error: " . $e->getMessage() . "\n"; }
    echo "\n";
}
