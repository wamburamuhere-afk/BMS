<?php
require 'roots.php';
global $pdo;

$stmt = $pdo->query("SELECT employee_id, employee_number, employee_code, email FROM employees");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo "ID: " . $row['employee_id'] . " | Num: " . $row['employee_number'] . " | Code: " . $row['employee_code'] . " | Email: " . $row['email'] . "\n";
}
