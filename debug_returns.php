<?php
require_once 'roots.php';
global $pdo;

$pdo->exec("ALTER TABLE purchase_returns ADD COLUMN warehouse_id INT NULL DEFAULT NULL AFTER supplier_id");
$pdo->exec("ALTER TABLE purchase_returns ADD COLUMN receipt_id INT NULL DEFAULT NULL AFTER purchase_order_id");

// Let's verify
$stmt = $pdo->query("DESCRIBE purchase_returns");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
