<?php
$pdo = new PDO('mysql:host=localhost;dbname=bms', 'root', '');
echo "--- EXPENSES ---\n";
$s = $pdo->query('DESCRIBE expenses');
print_r($s->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- BUDGETS ---\n";
$s = $pdo->query('DESCRIBE budgets');
print_r($s->fetchAll(PDO::FETCH_ASSOC));
