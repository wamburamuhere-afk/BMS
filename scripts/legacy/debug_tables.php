<?php
require_once 'roots.php';
require_once 'includes/config.php';
$tables = ['expenses', 'supplier_payments', 'purchase_orders', 'grn', 'transactions'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        echo "Table $table: " . $stmt->fetchColumn() . "\n";
    } catch (Exception $e) {
        echo "Table $table: ERROR (" . $e->getMessage() . ")\n";
    }
}
