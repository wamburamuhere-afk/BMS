<?php
require_once 'roots.php';
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT COUNT(*) FROM supplier_payments");
echo "Total payments: " . $stmt->fetchColumn() . "\n";
print_r($pdo->query("SELECT * FROM supplier_payments LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));
