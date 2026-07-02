<?php
require_once 'roots.php';
global $pdo;

function get_columns($table) {
    global $pdo;
    try {
        return $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { return [$e->getMessage()]; }
}

echo "POS_SALES: " . implode(", ", get_columns('pos_sales')) . "\n";
echo "INVOICES: " . implode(", ", get_columns('invoices')) . "\n";
echo "CUSTOMERS: " . implode(", ", get_columns('customers')) . "\n";
echo "CLIENTS: " . implode(", ", get_columns('clients')) . "\n";
