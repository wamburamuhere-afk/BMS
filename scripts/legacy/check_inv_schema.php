<?php
require_once 'roots.php';
require_once CONFIG_FILE;

$tables = ['warehouses', 'product_stocks', 'products'];
$schema = [];

foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        $schema[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $schema[$table] = 'Error: ' . $e->getMessage();
    }
}

header('Content-Type: application/json');
echo json_encode($schema, JSON_PRETTY_PRINT);
