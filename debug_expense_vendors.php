<?php
require_once 'roots.php';
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT vendor, amount, expense_date FROM expenses WHERE vendor IS NOT NULL AND vendor != ''");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
