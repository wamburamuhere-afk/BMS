<?php
/**
 * GRN (purchase_receipts) — three-approval workflow normalization.
 *
 * Existing schema:
 *   purchase_receipts.status ENUM('draft','completed','cancelled') DEFAULT 'draft'
 *   No audit columns beyond received_by INT.
 *
 * What this migration does:
 *   1. Expands the ENUM to include 'pending','reviewed','approved'
 *      (keeping legacy 'completed' for historical rows where stock has
 *      already been applied at receipt time).
 *   2. Promotes existing draft rows → pending.
 *   3. Adds reviewed_by/approved_by INT + name/role/at audit columns.
 *   4. Grants can_review + can_approve on page_key='grn' to Admin + MD.
 *
 * 'completed' rows are left UNTOUCHED. Their stock was added at create
 * time under the legacy flow; re-running the approval path would
 * double-count, so they're treated as a terminal legacy state.
 *
 * Idempotent — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: GRN three-approval workflow normalization...\n";

try {
    // ── 1. Add audit columns if missing ──────────────────────────────────
    $cols = [
        'reviewed_by'       => "ALTER TABLE purchase_receipts ADD COLUMN reviewed_by INT NULL AFTER received_by",
        'reviewed_by_name'  => "ALTER TABLE purchase_receipts ADD COLUMN reviewed_by_name VARCHAR(150) NULL AFTER reviewed_by",
        'reviewed_by_role'  => "ALTER TABLE purchase_receipts ADD COLUMN reviewed_by_role VARCHAR(100) NULL AFTER reviewed_by_name",
        'reviewed_at'       => "ALTER TABLE purchase_receipts ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_role",
        'approved_by'       => "ALTER TABLE purchase_receipts ADD COLUMN approved_by INT NULL AFTER reviewed_at",
        'approved_by_name'  => "ALTER TABLE purchase_receipts ADD COLUMN approved_by_name VARCHAR(150) NULL AFTER approved_by",
        'approved_by_role'  => "ALTER TABLE purchase_receipts ADD COLUMN approved_by_role VARCHAR(100) NULL AFTER approved_by_name",
        'approved_at'       => "ALTER TABLE purchase_receipts ADD COLUMN approved_at DATETIME NULL AFTER approved_by_role",
    ];
    foreach ($cols as $colName => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM purchase_receipts LIKE '$colName'")->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            $pdo->exec($sql);
            echo "  + Added column purchase_receipts.$colName.\n";
        } else {
            echo "  · Column purchase_receipts.$colName already exists.\n";
        }
    }

    // ── 2. Status enum normalization ─────────────────────────────────────
    $statusCol = $pdo->query("SHOW COLUMNS FROM purchase_receipts LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $needsExpand = $statusCol && strpos($statusCol['Type'], "'reviewed'") === false;

    if ($needsExpand) {
        // Expand enum to include both old + new values
        $pdo->exec("
            ALTER TABLE purchase_receipts MODIFY COLUMN status
            ENUM('draft','pending','reviewed','approved','completed','cancelled')
            NOT NULL DEFAULT 'pending'
        ");
        echo "  + Expanded status ENUM to include pending/reviewed/approved.\n";

        // Promote draft rows
        $promoted = $pdo->exec("UPDATE purchase_receipts SET status = 'pending' WHERE status = 'draft'");
        echo "  + Promoted $promoted draft row(s) to pending.\n";

        // Drop legacy 'draft' (keep 'completed' for backward compat with already-stocked rows)
        $pdo->exec("
            ALTER TABLE purchase_receipts MODIFY COLUMN status
            ENUM('pending','reviewed','approved','completed','cancelled')
            NOT NULL DEFAULT 'pending'
        ");
        echo "  + Removed legacy 'draft' from ENUM; default is now 'pending'.\n";
    } else {
        echo "  · status ENUM already canonical.\n";
    }

    // ── 3. Permission grants ─────────────────────────────────────────────
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'grn' LIMIT 1")->fetchColumn();
    if (!$perm) {
        echo "  ! permissions row for 'grn' is missing — skipping role grants.\n";
    } else {
        $upd = $pdo->prepare("
            UPDATE role_permissions
            SET can_review = 1, can_approve = 1
            WHERE permission_id = ? AND role_id IN (1, 2)
        ");
        $upd->execute([$perm]);
        echo "  + Granted can_review + can_approve on grn to roles 1 (Admin) and 2 (Managing Director) where present (" . $upd->rowCount() . " row(s)).\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
