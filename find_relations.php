<?php
require 'roots.php';
global $pdo;

echo "--- Project Related Tables ---\n";
$stmt = $pdo->query("SHOW TABLES LIKE '%project%'");
while($row = $stmt->fetch(PDO::FETCH_NUM)) {
    echo $row[0] . "\n";
}

echo "\n--- Tables with employee_id or staff_id ---\n";
$stmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME IN ('employee_id', 'staff_id')");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['TABLE_NAME'] . " (" . $row['COLUMN_NAME'] . ")\n";
}

echo "\n--- Employees Table Structure ---\n";
try {
    $stmt = $pdo->query("DESCRIBE employees");
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['Field'] . " - " . $row['Type'] . "\n";
    }
} catch (Exception $e) {
    echo "No employees table found.\n";
}
