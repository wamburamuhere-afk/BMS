<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: do_attachments table + nullable delivery_orders.dn_id...\n";

try {
    // ── 1. do_attachments table (mirrors rfq_attachments) ──────────────────
    $exists = $pdo->query("SHOW TABLES LIKE 'do_attachments'")->fetchColumn();
    if ($exists) {
        echo "Table do_attachments already exists — skipping create.\n";
    } else {
        $pdo->exec("
            CREATE TABLE do_attachments (
                do_attachment_id INT           NOT NULL AUTO_INCREMENT,
                do_id            INT           NOT NULL,
                attachment_name  VARCHAR(255)  NOT NULL DEFAULT '',
                file_path        VARCHAR(500)  NOT NULL,
                original_name    VARCHAR(255)  NOT NULL DEFAULT '',
                file_size        INT           DEFAULT NULL,
                uploaded_by      INT           DEFAULT NULL,
                uploaded_at      TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (do_attachment_id),
                KEY idx_do_id (do_id),
                KEY idx_uploaded_by (uploaded_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Created do_attachments table.\n";
    }

    // ── 2. delivery_orders.dn_id must be nullable ──────────────────────────
    // The project-based "Create DO" flow (create_do_full.php) creates a DO
    // directly from a project + items, with no source Delivery Note — the
    // column was NOT NULL from the older DN-derived create flow (api/create_do.php).
    $col = $pdo->query("SHOW COLUMNS FROM delivery_orders LIKE 'dn_id'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strtoupper($col['Null']) === 'NO') {
        $pdo->exec("ALTER TABLE delivery_orders MODIFY dn_id INT NULL DEFAULT NULL");
        echo "delivery_orders.dn_id is now nullable.\n";
    } else {
        echo "delivery_orders.dn_id already nullable (or column missing) — skipping.\n";
    }

    // ── 3. Upload directory + .htaccess ─────────────────────────────────────
    $dir = __DIR__ . '/../uploads/procurement/delivery_orders';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created uploads/procurement/delivery_orders directory.\n";
    } else {
        echo "uploads/procurement/delivery_orders directory already exists.\n";
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
        echo "Created .htaccess in uploads/procurement/delivery_orders/.\n";
    } else {
        echo ".htaccess already exists.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
