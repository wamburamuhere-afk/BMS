<?php
require 'roots.php';
global $pdo;

$stmt = $pdo->query("SELECT employee_id, first_name, last_name, employee_code, employee_number, email FROM employees WHERE employee_code = '' OR employee_code IS NULL");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Employees with empty/NULL employee_code:\n";
print_r($results);

$stmt = $pdo->query("SELECT employee_id, first_name, last_name, employee_code, employee_number, email FROM employees WHERE employee_number = '' OR employee_number IS NULL");
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nEmployees with empty/NULL employee_number:\n";
print_r($results);
