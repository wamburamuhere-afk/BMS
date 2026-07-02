<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
function printTable($pdo, $table) {
    echo "--- $table ---\n";
    try {
        $res = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($res as $row) {
            echo "{$row['Field']} ({$row['Type']})\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
printTable($pdo, 'products');
printTable($pdo, 'warehouses');
printTable($pdo, 'product_stock');
