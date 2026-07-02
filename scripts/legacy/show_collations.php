<?php
require 'roots.php';
global $pdo;

$stmt = $pdo->query("SHOW FULL COLUMNS FROM employees");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "Field: " . $col['Field'] . " | Collation: " . $col['Collation'] . "\n";
}
