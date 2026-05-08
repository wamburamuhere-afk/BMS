<?php
require 'roots.php';
global $pdo;

try {
    $pdo->exec("ALTER TABLE employees MODIFY employee_code VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    echo "Collation of employee_code updated successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
