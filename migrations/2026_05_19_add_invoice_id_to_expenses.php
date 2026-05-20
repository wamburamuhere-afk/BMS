<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add invoice_id to expenses...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'invoice_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN invoice_id INT NULL DEFAULT NULL AFTER paid_to_id");
        echo "Column invoice_id added to expenses.\n";
    } else {
        echo "Column invoice_id already exists — skipping.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
