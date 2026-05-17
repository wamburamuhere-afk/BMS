<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Create purchase_receipt_attachments table...\n";

try {
    $exists = $pdo->query("SHOW TABLES LIKE 'purchase_receipt_attachments'")->fetchColumn();
    if ($exists) {
        echo "Table purchase_receipt_attachments already exists — skipping.\n";
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS purchase_receipt_attachments (
                attachment_id INT          NOT NULL AUTO_INCREMENT,
                receipt_id    INT          NOT NULL,
                file_name     VARCHAR(255) NOT NULL,
                file_path     VARCHAR(255) NOT NULL,
                file_type     VARCHAR(100) DEFAULT NULL,
                file_size     INT          DEFAULT NULL,
                uploaded_by   INT          DEFAULT NULL,
                uploaded_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
                description   TEXT         DEFAULT NULL,
                PRIMARY KEY (attachment_id),
                KEY idx_receipt_id (receipt_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Created purchase_receipt_attachments table.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
