<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';

try {
    echo "Adding delivery_note column to purchase_receipts table...\n";
    
    $pdo->exec("
        ALTER TABLE purchase_receipts 
        ADD COLUMN delivery_note VARCHAR(100) NULL AFTER receipt_date
    ");
    
    echo "Success! Column added.\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
