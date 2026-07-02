<?php
require_once 'roots.php';
global $pdo;
$stmt = $pdo->query("DESCRIBE purchase_orders");
echo "<pre>";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
echo "</pre>";
