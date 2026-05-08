<?php
require 'roots.php';
global $pdo;

$id = 4;
$stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
$stmt->execute([$id]);
print_r($stmt->fetch(PDO::FETCH_ASSOC));
