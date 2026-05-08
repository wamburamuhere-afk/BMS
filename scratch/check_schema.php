<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
$cols = $pdo->query('DESCRIBE sales_orders')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $col) {
    echo $col['Field'] . " - " . $col['Type'] . " - Null: " . $col['Null'] . "\n";
}
echo "----\n";
$cols = $pdo->query('DESCRIBE invoices')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $col) {
    echo $col['Field'] . " - " . $col['Type'] . " - Null: " . $col['Null'] . "\n";
}
