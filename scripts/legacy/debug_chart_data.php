<?php
require_once 'includes/config.php';
$s1 = $pdo->query('SELECT MIN(invoice_date), MAX(invoice_date) FROM invoices')->fetch();
$s2 = $pdo->query('SELECT MIN(sale_date), MAX(sale_date) FROM pos_sales')->fetch();
echo 'Invoices: ' . json_encode($s1) . PHP_EOL;
echo 'POS: ' . json_encode($s2) . PHP_EOL;
