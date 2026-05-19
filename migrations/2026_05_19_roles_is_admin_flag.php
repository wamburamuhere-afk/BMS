<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add is_admin flag to roles table...\n";

try {
    // 1. Add is_admin column if not present
    $col = $pdo->query("SHOW COLUMNS FROM roles LIKE 'is_admin'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE roles ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role_name");
        echo "Column is_admin added to roles.\n";
    } else {
        echo "Column is_admin already exists, skipping ALTER.\n";
    }

    // 2. Mark the existing 'Admin' role (role_id = 1) as admin
    $pdo->exec("UPDATE roles SET is_admin = 1 WHERE role_id = 1");
    echo "role_id=1 marked as is_admin=1.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
