<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT customer_id, customer_name FROM customers ORDER BY customer_id DESC LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
