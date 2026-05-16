<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add display_name to inspection_attachments...\n";

try {
    $res = $pdo->query("SHOW COLUMNS FROM inspection_attachments LIKE 'display_name'");
    if (!$res->fetch()) {
        $pdo->exec("ALTER TABLE inspection_attachments ADD COLUMN display_name VARCHAR(255) NULL AFTER original_name");
        echo "✓ Column 'display_name' added to 'inspection_attachments'.\n";
    } else {
        echo "✓ Column 'display_name' already exists — skipped.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
