<?php
require_once 'includes/config.php';
$id = $_GET['id'] ?? 8; // Default to 8 or whatever is likely to exist
$stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
header('Content-Type: application/json');
echo json_encode($customer, JSON_PRETTY_PRINT);
