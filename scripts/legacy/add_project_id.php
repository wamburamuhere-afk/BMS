<?php
require_once 'roots.php';
try {
    $pdo->exec("ALTER TABLE purchase_returns ADD COLUMN project_id INT NULL AFTER receipt_id");
    echo "Column project_id added successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
