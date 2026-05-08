<?php
require_once 'c:/wamp64/www/bms/includes/config.php';

try {
    // Change location columns to VARCHAR to support free text names
    $sql = "ALTER TABLE tenders 
            MODIFY COLUMN region_id VARCHAR(255) NULL,
            MODIFY COLUMN district_id VARCHAR(255) NULL,
            MODIFY COLUMN council_id VARCHAR(255) NULL,
            MODIFY COLUMN ward_id VARCHAR(255) NULL";
    
    $pdo->exec($sql);
    echo "Database updated successfully: Columns changed to VARCHAR.";
} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage();
}
