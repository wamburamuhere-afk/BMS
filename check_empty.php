<?php
require 'roots.php';
global $pdo;

$fields = ['employee_code', 'employee_number', 'email'];
foreach ($fields as $field) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE $field = ''");
    echo "$field empty string count: " . $stmt->fetchColumn() . "\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE $field IS NULL");
    echo "$field NULL count: " . $stmt->fetchColumn() . "\n";
}
