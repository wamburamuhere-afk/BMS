<?php
require_once __DIR__ . '/roots.php';

function describeTable($pdo, $tableName) {
    echo "--- Table: $tableName ---\n";
    try {
        $stmt = $pdo->query("DESCRIBE $tableName");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "Field: {$row['Field']}, Type: {$row['Type']}\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

describeTable($pdo, 'products');
describeTable($pdo, 'categories');
describeTable($pdo, 'sales_order_items');
describeTable($pdo, 'sales_orders');
describeTable($pdo, 'customers');
