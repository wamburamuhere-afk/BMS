<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
echo "--- pos_sales ---\n";
print_r($pdo->query('DESCRIBE pos_sales')->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- pos_sale_items ---\n";
print_r($pdo->query('DESCRIBE pos_sale_items')->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- invoices ---\n";
print_r($pdo->query('DESCRIBE invoices')->fetchAll(PDO::FETCH_ASSOC));
