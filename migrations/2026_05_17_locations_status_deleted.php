<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add 'deleted' to locations.status ENUM...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM locations LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "locations table or status column not found — skipping.\n";
        exit(0);
    }

    if (strpos($col['Type'], 'deleted') !== false) {
        echo "locations.status already includes 'deleted' — skipping.\n";
        exit(0);
    }

    $pdo->exec("ALTER TABLE locations MODIFY COLUMN status ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active'");
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
