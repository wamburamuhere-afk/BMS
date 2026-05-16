<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Add purchase_order_id to deliveries...\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'purchase_order_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN purchase_order_id INT NULL DEFAULT NULL");
        echo "Added purchase_order_id column to deliveries.\n";
    } else {
        echo "purchase_order_id column already exists — skipping.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
