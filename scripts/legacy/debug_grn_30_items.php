<?php
require_once 'roots.php';
global $pdo;

echo "Receipt Items for GRN 30:\n";
$stmt = $pdo->prepare("SELECT * FROM receipt_items WHERE receipt_id = 30");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
