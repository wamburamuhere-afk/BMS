<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add pending/reviewed/approved to customer_lpos status...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM customer_lpos LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "Table customer_lpos not found — skipping.\n";
        exit(0);
    }
    if (strpos($col['Type'], 'pending') !== false) {
        echo "Status ENUM already updated — skipping.\n";
    } else {
        $pdo->exec("
            ALTER TABLE customer_lpos
            MODIFY COLUMN status
                ENUM('pending','reviewed','approved','open','partially_fulfilled','fulfilled','cancelled')
                NOT NULL DEFAULT 'pending'
        ");
        echo "Status ENUM updated with pending/reviewed/approved.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
