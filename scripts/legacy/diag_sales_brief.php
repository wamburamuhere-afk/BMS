<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
function printTable($pdo, $table) {
    echo "--- $table ---\n";
    $res = $pdo->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($res as $row) {
        echo "{$row['Field']} ({$row['Type']})\n";
    }
    echo "\n";
}
printTable($pdo, 'pos_sales');
printTable($pdo, 'pos_sale_items');
printTable($pdo, 'invoices');
