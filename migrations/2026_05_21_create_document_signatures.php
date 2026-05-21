<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create document_signatures table...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS document_signatures (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            document_id         INT             NOT NULL,
            signature_id        INT             NULL,
            requested_by        INT             NOT NULL,
            signed_by           INT             NULL,
            signature_position  VARCHAR(50)     NOT NULL DEFAULT 'bottom_right',
            ip_address          VARCHAR(45)     NULL,
            status              ENUM('pending','signed','rejected') NOT NULL DEFAULT 'pending',
            due_date            DATE            NULL,
            signed_at           TIMESTAMP       NULL,
            created_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_document_id (document_id),
            INDEX idx_signed_by (signed_by),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Table document_signatures created (or already exists).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
