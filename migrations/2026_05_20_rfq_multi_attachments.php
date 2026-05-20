<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: rfq_attachments table + drop single attachment column...\n";

try {
    // Create rfq_attachments table
    $exists = $pdo->query("SHOW TABLES LIKE 'rfq_attachments'")->fetchColumn();
    if ($exists) {
        echo "Table rfq_attachments already exists — skipping create.\n";
    } else {
        $pdo->exec("
            CREATE TABLE rfq_attachments (
                attachment_id   INT           NOT NULL AUTO_INCREMENT,
                rfq_id          INT           NOT NULL,
                attachment_name VARCHAR(255)  NOT NULL DEFAULT '',
                file_path       VARCHAR(500)  NOT NULL,
                original_name   VARCHAR(255)  NOT NULL DEFAULT '',
                file_size       INT           DEFAULT NULL,
                uploaded_by     INT           DEFAULT NULL,
                uploaded_at     TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (attachment_id),
                KEY idx_rfq_id (rfq_id),
                KEY idx_uploaded_by (uploaded_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Created rfq_attachments table.\n";
    }

    // Drop the single attachment column if it exists (replaced by the table above)
    $col = $pdo->query("SHOW COLUMNS FROM rfq LIKE 'attachment'")->fetchColumn();
    if ($col) {
        $pdo->exec("ALTER TABLE rfq DROP COLUMN attachment");
        echo "Dropped rfq.attachment column.\n";
    } else {
        echo "rfq.attachment column not present — skipping drop.\n";
    }

    // Ensure upload directory + .htaccess exist
    $dir = __DIR__ . '/../uploads/procurement/rfq';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created uploads/procurement/rfq directory.\n";
    } else {
        echo "uploads/procurement/rfq directory already exists.\n";
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess,
'<FilesMatch "\.(php|php5|phtml|pl|py|jsp|asp|sh|cgi)$">
    Require all denied
</FilesMatch>
Options -ExecCGI
RemoveHandler .php .phtml .php5
RemoveType .php .phtml .php5
');
        echo "Created .htaccess in uploads/procurement/rfq/.\n";
    } else {
        echo ".htaccess already exists.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
