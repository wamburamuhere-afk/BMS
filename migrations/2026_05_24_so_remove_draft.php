<?php
/**
 * Sales Orders — remove 'draft' from the workflow.
 *
 * Per three_approval.md a sales order's lifecycle starts at 'pending'.
 * 'draft' was a legacy pre-workflow state. This migration:
 *
 *   1. Promotes every existing sales_orders.status='draft' row to 'pending'
 *      (regardless of is_quote, since draft is being removed from the schema).
 *   2. Drops 'draft' from the ENUM and sets the default to 'pending'.
 *
 * Idempotent — re-running is a no-op once the enum has been updated.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: remove 'draft' from sales_orders.status...\n";

try {
    // ── 1. Promote draft rows to pending ────────────────────────────────
    $promoted = $pdo->exec("UPDATE sales_orders SET status = 'pending' WHERE status = 'draft'");
    echo "  + Promoted $promoted draft row(s) to pending.\n";

    // ── 2. Drop 'draft' from the enum and set default to pending ────────
    $statusCol = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusCol && strpos($statusCol['Type'], "'draft'") !== false) {
        $pdo->exec("
            ALTER TABLE sales_orders MODIFY COLUMN status
            ENUM('pending','reviewed','approved','processing','shipped','delivered','completed','cancelled')
            NOT NULL DEFAULT 'pending'
        ");
        echo "  + Removed 'draft' from status ENUM; default is now 'pending'.\n";
    } else {
        echo "  · 'draft' already absent from status ENUM.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
