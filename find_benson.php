<?php
require_once 'includes/config.php';
$stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_name LIKE ?");
$stmt->execute(['%Benson%']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
