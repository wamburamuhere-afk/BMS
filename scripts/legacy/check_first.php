<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT * FROM customers ORDER BY customer_id ASC LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_PRETTY_PRINT);
