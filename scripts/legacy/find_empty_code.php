<?php
require 'roots.php';
global $pdo;

$stmt = $pdo->query("SELECT employee_id, first_name, last_name, employee_number, employee_code FROM employees WHERE employee_code = ''");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
