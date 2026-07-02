<?php
require_once 'roots.php';
global $pdo;
$tables = ['pos_sales', 'invoices', 'customers', 'pos_sale_items', 'invoice_items', 'products'];
foreach ($tables as $t) {
    echo "$t: ";
    try {
        $c = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_COLUMN);
        echo implode(', ', $c) . "\n";
    } catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
}
