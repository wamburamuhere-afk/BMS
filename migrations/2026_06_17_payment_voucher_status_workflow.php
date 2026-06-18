<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: payment_vouchers status workflow (pending→reviewed→approved→paid)...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "Column 'status' not found on payment_vouchers — skipping.\n";
        exit(0);
    }

    if (strpos($col['Type'], 'reviewed') !== false && strpos($col['Type'], 'pending') !== false) {
        echo "ENUM already contains 'pending' and 'reviewed' — skipping ALTER.\n";
    } else {
        $pdo->exec("
            ALTER TABLE payment_vouchers
            MODIFY COLUMN status
                ENUM('pending','reviewed','approved','paid','cancelled','draft')
                NOT NULL DEFAULT 'pending'
        ");
        echo "Status ENUM updated with 'pending' and 'reviewed'. Default set to 'pending'.\n";
    }

    // Migrate existing draft records to pending
    $affected = $pdo->exec("UPDATE payment_vouchers SET status = 'pending' WHERE status = 'draft'");
    echo "Migrated $affected existing 'draft' voucher(s) to 'pending'.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
