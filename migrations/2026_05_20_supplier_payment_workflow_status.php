<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add reviewed/approved to supplier_payments status ENUM...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM supplier_payments LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "Table supplier_payments not found — skipping.\n";
        exit(0);
    }
    if (strpos($col['Type'], 'reviewed') !== false) {
        echo "Status ENUM already includes reviewed/approved — skipping.\n";
    } else {
        $pdo->exec("
            ALTER TABLE supplier_payments
            MODIFY COLUMN status
                ENUM('pending','reviewed','approved','completed','cancelled','failed')
                NOT NULL DEFAULT 'pending'
        ");
        echo "Status ENUM updated with reviewed/approved.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
