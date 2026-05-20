<?php
require_once __DIR__ . '/../roots.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_lpo_attachments (
        attachment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lpo_id        INT UNSIGNED NOT NULL,
        file_path     VARCHAR(500) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_size     INT UNSIGNED DEFAULT 0,
        created_by    INT UNSIGNED,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lpo_attach_lpo_id (lpo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "Migration 2026_05_20_create_lpo_attachments: customer_lpo_attachments table created OK\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
