<?php
require_once 'roots.php';
$stmt = $pdo->query("SHOW FULL COLUMNS FROM product_stocks");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($data, JSON_PRETTY_PRINT);
