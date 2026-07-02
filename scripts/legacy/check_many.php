<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT * FROM customers ORDER BY customer_id DESC LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
