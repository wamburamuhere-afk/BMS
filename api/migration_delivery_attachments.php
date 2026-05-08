<?php
// File: api/migration_delivery_attachments.php
require_once __DIR__ . '/../roots.php';

try {
    echo "Creating delivery_attachments table...\n";
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS delivery_attachments (
            attachment_id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_id INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(100) NULL,
            file_size INT NULL,
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            uploaded_by INT NULL,
            INDEX (delivery_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    echo "Success! Table created.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
