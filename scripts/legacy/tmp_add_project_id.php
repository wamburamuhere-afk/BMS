<?php
require 'roots.php';
try {
    $pdo->exec('ALTER TABLE warehouses ADD COLUMN project_id INT NULL AFTER warehouse_id');
    echo "Column project_id added successfully.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column project_id already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
