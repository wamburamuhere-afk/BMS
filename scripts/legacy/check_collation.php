<?php
require_once 'roots.php';
$stmt = $pdo->query("SHOW FULL COLUMNS FROM purchase_orders");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt = $pdo->query("SHOW FULL COLUMNS FROM purchase_receipts");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
