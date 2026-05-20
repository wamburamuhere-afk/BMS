<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add dn_number to deliveries...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'dn_number'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN dn_number VARCHAR(100) NULL DEFAULT NULL AFTER delivery_number");
        echo "Column dn_number added to deliveries.\n";
    } else {
        echo "Column dn_number already exists — skipping.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
