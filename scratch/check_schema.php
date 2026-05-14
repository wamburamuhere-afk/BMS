<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

function checkTable($tableName) {
    global $pdo;
    echo "--- Table: $tableName ---\n";
    try {
        $stmt = $pdo->query("DESCRIBE $tableName");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "{$row['Field']} - {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

checkTable('purchase_orders');
checkTable('sub_contractors');
checkTable('supplier_payments');
checkTable('projects');
