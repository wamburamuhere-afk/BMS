<?php
require 'roots.php';
global $pdo;

try {
    $pdo->exec("ALTER TABLE expenses ADD COLUMN paid_to_type ENUM('supplier', 'staff') DEFAULT NULL AFTER vendor");
    $pdo->exec("ALTER TABLE expenses ADD COLUMN paid_to_id INT DEFAULT NULL AFTER paid_to_type");
    echo "Columns paid_to_type and paid_to_id added successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
