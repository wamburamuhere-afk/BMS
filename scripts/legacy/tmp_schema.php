<?php
require_once 'roots.php';
global $pdo;
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo implode("\n", $tables);
echo "\n--- DESCRIBE pos_sales ---\n";
$desc = $pdo->query("DESCRIBE pos_sales")->fetchAll(PDO::FETCH_ASSOC);
print_r($desc);
echo "\n--- DESCRIBE invoices ---\n";
$desc = $pdo->query("DESCRIBE invoices")->fetchAll(PDO::FETCH_ASSOC);
print_r($desc);
echo "\n--- DESCRIBE pos_sale_items ---\n";
$desc = $pdo->query("DESCRIBE pos_sale_items")->fetchAll(PDO::FETCH_ASSOC);
print_r($desc);
echo "\n--- DESCRIBE invoice_items ---\n";
$desc = $pdo->query("DESCRIBE invoice_items")->fetchAll(PDO::FETCH_ASSOC);
print_r($desc);
