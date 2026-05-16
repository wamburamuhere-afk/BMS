<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add display_name to inspection_attachments...\n";

try {
    // Guard: table may not exist yet on servers that haven't run inspection_extras.
    // inspection_extras creates the table with display_name already included, so skip here.
    $tbl = $pdo->query("SHOW TABLES LIKE 'inspection_attachments'")->fetch();
    if (!$tbl) {
        echo "Table 'inspection_attachments' not found — display_name will be included when inspection_extras creates it.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $res = $pdo->query("SHOW COLUMNS FROM inspection_attachments LIKE 'display_name'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE inspection_attachments ADD COLUMN display_name VARCHAR(255) NULL");
        echo "✓ Column 'display_name' added to 'inspection_attachments'.\n";
    } else {
        echo "✓ Column 'display_name' already exists — skipped.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
