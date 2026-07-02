<?php
require 'roots.php';
global $pdo;

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tender_staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tender_id INT,
        employee_id INT,
        role_position VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Table tender_staff created or already exists.\n";
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}
?>
