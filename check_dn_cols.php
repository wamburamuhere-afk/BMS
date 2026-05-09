<?php
require_once 'roots.php';
global $pdo;

$tables = ['delivery_orders', 'delivery_notes', 'deliveries', 'delivery_items'];
foreach ($tables as $table) {
    echo "COLUMNS IN $table:\n";
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo "Table $table not found.\n";
    }
    echo "\n-----------------------------------\n";
}
