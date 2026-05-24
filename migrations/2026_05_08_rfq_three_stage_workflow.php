<?php
/**
 * RFQ — three-stage workflow normalization.
 *
 * Replaces the legacy token-guarded api/migrate_rfq_workflow.php so this
 * runs automatically via migrations/runner.php on every deploy (and on
 * every new server) instead of needing a manual browser hit.
 *
 * What this migration does:
 *   1. Adds the 10 prepared/reviewed/approved audit columns to `rfq`.
 *   2. Expands rfq.status ENUM to include 'review'.
 *   3. Backfills prepared_by_name / prepared_by_role for existing rows.
 *
 * The role_permissions.can_review / can_approve columns that the original
 * api/migrate_rfq_workflow.php added are NOT re-added here — they are
 * already owned by:
 *   - migrations/2026_05_19_received_invoices_can_approve.php (can_approve)
 *   - migrations/2026_05_22_role_permissions_can_review.php   (can_review)
 *
 * Idempotent — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: RFQ three-stage workflow normalization...\n";

try {
    // ── 1. Add audit columns to rfq if missing ───────────────────────────
    $cols = [
        'prepared_by_name' => "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Full name of creator at time of creation'",
        'prepared_by_role' => "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Role of creator at time of creation'",
        'reviewed_by'      => "INT NULL DEFAULT NULL COMMENT 'User ID of reviewer'",
        'reviewed_by_name' => "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Full name of reviewer at time of review'",
        'reviewed_by_role' => "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Role of reviewer at time of review'",
        'reviewed_at'      => "DATETIME NULL DEFAULT NULL COMMENT 'Timestamp of review action'",
        'approved_by'      => "INT NULL DEFAULT NULL COMMENT 'User ID of approver'",
        'approved_by_name' => "VARCHAR(150) NULL DEFAULT NULL COMMENT 'Full name of approver at time of approval'",
        'approved_by_role' => "VARCHAR(100) NULL DEFAULT NULL COMMENT 'Role of approver at time of approval'",
        'approved_at'      => "DATETIME NULL DEFAULT NULL COMMENT 'Timestamp of approval action'",
    ];
    foreach ($cols as $colName => $def) {
        $exists = $pdo->query("SHOW COLUMNS FROM rfq LIKE '$colName'")->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            $pdo->exec("ALTER TABLE rfq ADD COLUMN $colName $def");
            echo "  + Added column rfq.$colName.\n";
        } else {
            echo "  · Column rfq.$colName already exists.\n";
        }
    }

    // ── 2. Status enum: add 'review' if missing ──────────────────────────
    $statusCol = $pdo->query("SHOW COLUMNS FROM rfq LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusCol && strpos($statusCol['Type'], "'review'") === false) {
        $pdo->exec("
            ALTER TABLE rfq MODIFY COLUMN status
            ENUM('draft','review','approved','sent','received',
                 'evaluated','awarded','partially','completed','cancelled')
            NOT NULL DEFAULT 'draft'
        ");
        echo "  + Added 'review' to rfq.status ENUM.\n";
    } else {
        echo "  · rfq.status ENUM already contains 'review'.\n";
    }

    // ── 3. Backfill prepared_by_name / role for existing rows ────────────
    $todo = (int) $pdo->query("
        SELECT COUNT(*) FROM rfq
        WHERE prepared_by_name IS NULL AND created_by IS NOT NULL
    ")->fetchColumn();

    if ($todo > 0) {
        $pdo->exec("
            UPDATE rfq r
            JOIN users u ON r.created_by = u.user_id
            SET r.prepared_by_name =
                TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')))
            WHERE r.prepared_by_name IS NULL AND r.created_by IS NOT NULL
        ");
        $pdo->exec("
            UPDATE rfq SET prepared_by_role = 'Staff'
            WHERE prepared_by_role IS NULL AND prepared_by_name IS NOT NULL
        ");
        echo "  + Backfilled prepared_by_name on $todo existing RFQ row(s).\n";
    } else {
        echo "  · No RFQ rows need prepared_by_name backfill.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
