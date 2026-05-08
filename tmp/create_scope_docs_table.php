<?php
include 'c:/wamp64/www/bms/roots.php';
global $pdo;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_scope_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        scope_type VARCHAR(50) NOT NULL,
        addendum_no VARCHAR(50) DEFAULT NULL,
        file_name VARCHAR(255),
        file_path VARCHAR(255),
        uploaded_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Created project_scope_documents table\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
