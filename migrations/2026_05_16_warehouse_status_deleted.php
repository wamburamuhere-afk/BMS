<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Add 'deleted' to warehouses.status enum...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM warehouses LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], 'deleted') !== false) {
        echo "Status enum already includes 'deleted' — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE warehouses MODIFY COLUMN status ENUM('active','inactive','maintenance','deleted') NOT NULL DEFAULT 'active'");
        echo "Added 'deleted' to warehouses.status enum.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
