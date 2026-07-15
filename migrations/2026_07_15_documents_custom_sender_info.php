<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: documents_custom_sender_info...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM documents LIKE 'custom_sender_info'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE documents
                    ADD COLUMN custom_sender_info TEXT NULL DEFAULT NULL AFTER signature_align");
        echo "  + Added documents.custom_sender_info.\n";
    } else {
        echo "  ~ documents.custom_sender_info already exists — skipped.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
