<?php
require_once 'roots.php';
require_once 'includes/config.php';
echo "COLUMNS IN expenses:\n";
print_r($pdo->query("DESCRIBE expenses")->fetchAll(PDO::FETCH_ASSOC));
echo "\nCOLUMNS IN supplier_payments:\n";
print_r($pdo->query("DESCRIBE supplier_payments")->fetchAll(PDO::FETCH_ASSOC));
