<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: extend invoices.status ENUM to include all required values...\n";

try {
    // Check whether 'overdue' is already in the ENUM — if it is, nothing to do.
    $col = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], 'overdue') !== false) {
        echo "invoices.status already contains 'overdue' — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $pdo->exec("ALTER TABLE invoices
        MODIFY COLUMN status
        ENUM('draft','pending','reviewed','approved','sent','paid','partial','overdue','cancelled')
        NULL DEFAULT 'pending'");
    echo "  - invoices.status ENUM extended.\n";

    // Fix any rows with a blank status that have approved_by stamped (should be 'approved').
    $fixed = $pdo->exec("UPDATE invoices SET status = 'approved' WHERE (status IS NULL OR status = '') AND approved_by IS NOT NULL");
    if ($fixed > 0) echo "  - fixed $fixed invoice(s) with blank status → 'approved'.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
