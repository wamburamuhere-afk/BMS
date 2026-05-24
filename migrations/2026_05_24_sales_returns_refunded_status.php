<?php
/**
 * Sales Returns — add 'refunded' to the status ENUM.
 *
 * Bug: the UI offered a "Mark as Refunded" button
 * (app/bms/sales/sales_returns/sales_return_view.php line 96 — calls
 * changeStatus(id, 'refunded')) but the DB enum was
 *
 *   enum('pending','approved','rejected','completed','cancelled')
 *
 * which does NOT contain 'refunded'. When a user clicked the button, MySQL
 * silently truncated the value to '' (warning 1265), corrupting the row.
 * Production was reporting
 *
 *   "SQLSTATE[01000]: Warning: 1265 Data truncated for column 'status'
 *    at row 1"
 *
 * Fix:
 *   1. Extend the enum to include 'refunded'.
 *   2. Repair any rows whose status was previously truncated to ''
 *      (they were users clicking "Mark as Refunded" before this fix).
 *      Setting them to 'refunded' matches the original user intent.
 *      An admin can correct any false positives via the now-working UI.
 *
 * Idempotent — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add 'refunded' to sales_returns.status ENUM...\n";

try {
    // ── 1. Extend the enum ──────────────────────────────────────────────
    $col = $pdo->query("SHOW COLUMNS FROM sales_returns LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        echo "  ! sales_returns.status column not found — aborting.\n";
        exit(1);
    }
    if (strpos($col['Type'], "'refunded'") === false) {
        $pdo->exec("
            ALTER TABLE sales_returns MODIFY COLUMN status
            ENUM('pending','approved','rejected','completed','cancelled','refunded')
            NOT NULL DEFAULT 'pending'
        ");
        echo "  + Added 'refunded' to sales_returns.status ENUM.\n";
    } else {
        echo "  · 'refunded' already in sales_returns.status ENUM.\n";
    }

    // ── 2. Repair rows previously corrupted by the truncation ──────────
    // status='' is the silent-truncation signature. The user clicked
    // "Mark Refunded" so the original intent is captured.
    $repaired = $pdo->exec("
        UPDATE sales_returns
        SET status = 'refunded'
        WHERE status = '' OR status IS NULL
    ");
    if ($repaired > 0) {
        echo "  + Repaired $repaired previously-truncated row(s) → status='refunded'.\n";
    } else {
        echo "  · No truncated rows to repair.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
