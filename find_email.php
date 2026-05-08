<?php
require 'roots.php';
global $pdo;

$email = 'bensohenson@gmail.com';
$stmt = $pdo->prepare("SELECT employee_id, first_name, last_name, email FROM employees WHERE email = ?");
$stmt->execute([$email]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
