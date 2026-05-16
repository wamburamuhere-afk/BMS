<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Create purchase_order_attachments table...\n";

try {
    $exists = $pdo->query("SHOW TABLES LIKE 'purchase_order_attachments'")->fetchColumn();
    if ($exists) {
        echo "Table purchase_order_attachments already exists — skipping.\n";
    } else {
        $pdo->exec("
            CREATE TABLE purchase_order_attachments (
                attachment_id   INT           NOT NULL AUTO_INCREMENT,
                purchase_order_id INT         NOT NULL,
                file_name       VARCHAR(255)  NOT NULL,
                file_path       VARCHAR(500)  NOT NULL,
                file_type       VARCHAR(100)  DEFAULT NULL,
                file_size       INT           DEFAULT NULL,
                uploaded_by     INT           DEFAULT NULL,
                uploaded_at     TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
                description     TEXT          DEFAULT NULL,
                PRIMARY KEY (attachment_id),
                KEY idx_purchase_order (purchase_order_id),
                KEY idx_uploaded_by (uploaded_by),
                KEY idx_uploaded_at (uploaded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Created purchase_order_attachments table.\n";
    }

    // Ensure uploads directory exists
    $dir = __DIR__ . '/../uploads/purchase_orders';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created uploads/purchase_orders directory.\n";
    } else {
        echo "uploads/purchase_orders directory already exists.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
