<?php
require_once 'includes/config.php';
$id = 8; 
$stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($customer, JSON_PRETTY_PRINT);
