<?php
/**
 * 2026_06_02_received_invoice_three_stage.php
 * -------------------------------------------
 * Moves received (supplier) invoices to a three-stage workflow:
 *     pending  ->  reviewed  ->  approved
 *
 * Maps existing data: draft -> pending, submitted -> reviewed. The approved /
 * paid / deleted statuses are preserved. Signatures for created/reviewed/
 * approved live in the shared workflow_signatures table (no new columns here).
 *
 * Idempotent: only runs the enum/data change while 'draft' is still a valid
 * status value.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: received invoice three-stage workflow...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM supplier_invoices LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { echo "  ! status column missing — skipping.\n"; exit(0); }

    if (strpos($col['Type'], "'pending'") !== false && strpos($col['Type'], "'draft'") === false) {
        echo "  · already on the three-stage statuses, skipping.\n";
        echo "\nMigration complete.\n";
        exit(0);
    }

    // 1. Widen the enum to a superset so we can remap values safely.
    $pdo->exec("ALTER TABLE supplier_invoices
                MODIFY COLUMN status ENUM('draft','submitted','pending','reviewed','approved','paid','deleted')
                NOT NULL DEFAULT 'pending'");
    echo "  + enum widened to superset.\n";

    // 2. Remap legacy statuses.
    $d = $pdo->exec("UPDATE supplier_invoices SET status='pending'  WHERE status='draft'");
    $s = $pdo->exec("UPDATE supplier_invoices SET status='reviewed' WHERE status='submitted'");
    echo "  + remapped draft->pending ({$d} row(s)), submitted->reviewed ({$s} row(s)).\n";

    // 3. Narrow the enum to the final three-stage set.
    $pdo->exec("ALTER TABLE supplier_invoices
                MODIFY COLUMN status ENUM('pending','reviewed','approved','paid','deleted')
                NOT NULL DEFAULT 'pending'");
    echo "  + enum finalised: pending, reviewed, approved, paid, deleted.\n";

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
