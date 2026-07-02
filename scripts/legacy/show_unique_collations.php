<?php
require 'roots.php';
global $pdo;

$stmt = $pdo->query("SHOW FULL COLUMNS FROM employees");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    if (in_array($col['Field'], ['employee_code', 'employee_number', 'email'])) {
        echo "Field: " . $col['Field'] . " | Collation: " . $col['Collation'] . "\n";
    }
}
