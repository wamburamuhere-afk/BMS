<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Add attachment column to rfq table...\n";

$rfqExists = (bool)$pdo->query("SHOW TABLES LIKE 'rfq'")->fetchColumn();
if (!$rfqExists) {
    echo "  · rfq table does not exist on this database — skipped.\n";
    echo "Migration complete.\n";
    exit(0);
}

try {
    $col = $pdo->query("SHOW COLUMNS FROM rfq LIKE 'attachment'")->fetchColumn();
    if ($col) {
        echo "Column rfq.attachment already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE rfq ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER deadline_date");
        echo "Added attachment column to rfq table.\n";
    }

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
        echo ".htaccess already exists — skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
