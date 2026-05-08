<?php
require_once 'roots.php';
try {
    // Add logo_path to customers if not exists
    $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) AFTER acronym");
    echo "Column logo_path added to customers successfully (or already exists).\n";
    
    // Add logo_path to suppliers
    $pdo->exec("ALTER TABLE suppliers ADD COLUMN logo_path VARCHAR(255) AFTER acronym");
    echo "Column logo_path added to suppliers successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
