<?php
require_once '../roots.php';
global $pdo;

$tables = ['stock_movements', 'purchase_receipts', 'receipt_items', 'products', 'purchase_orders'];

echo "=== Schema Check ===\n";
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SHOW CREATE TABLE $table");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "\n[TABLE: $table]\n";
        echo $row['Create Table'] . "\n";
    } catch (PDOException $e) {
        echo "\n[ERROR] Table $table: " . $e->getMessage() . "\n";
    }
}
?>
