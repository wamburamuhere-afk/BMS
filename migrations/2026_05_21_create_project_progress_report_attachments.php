<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: create project_progress_report_attachments table...\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_progress_report_attachments (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        report_id       INT NOT NULL,
        attachment_name VARCHAR(255) NOT NULL,
        file_path       VARCHAR(500) NOT NULL,
        file_size       INT NOT NULL DEFAULT 0,
        file_ext        VARCHAR(10)  NOT NULL DEFAULT '',
        created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_report_id (report_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
