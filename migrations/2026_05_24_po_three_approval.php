<?php
/**
 * PO Three-Approval Workflow normalization.
 *
 * 1. Rename the legacy enum value 'review' → 'reviewed' on `purchase_orders.status`.
 *    (Existing rows with status='review' are migrated to 'reviewed'.)
 * 2. Ensure `reviewed_by` INT column exists (joinable FK; the snapshot pair
 *    reviewed_by_name / reviewed_by_role already exists and is preserved).
 * 3. Permission row for page_key='purchase_orders' already exists (id=104) —
 *    seed `can_review` + `can_approve` for Admin & Managing Director roles so
 *    a tester can actually exercise the workflow after deploy.
 *
 * Idempotent — safe to run more than once.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: PO three-approval workflow normalization...\n";

try {
    // ── 1. Add reviewed_by INT column if missing ─────────────────────────
    $col = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'reviewed_by'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE purchase_orders
                    ADD COLUMN reviewed_by INT NULL AFTER reviewed_by_role");
        echo "  + Added column purchase_orders.reviewed_by INT.\n";
    } else {
        echo "  · Column purchase_orders.reviewed_by already exists.\n";
    }

    // ── 2. Rename enum value 'review' → 'reviewed' ───────────────────────
    $statusCol = $pdo->query("SHOW COLUMNS FROM purchase_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusCol && strpos($statusCol['Type'], "'reviewed'") === false) {
        // Expand the enum to contain BOTH 'review' and 'reviewed' so existing
        // rows are not orphaned by MySQL's truncation behaviour.
        $pdo->exec("
            ALTER TABLE purchase_orders MODIFY COLUMN status
            ENUM('draft','pending','review','reviewed','approved','ordered','partially_received','received','cancelled','completed')
            NOT NULL DEFAULT 'pending'
        ");
        echo "  + Expanded status ENUM to include 'reviewed'.\n";

        // Migrate any existing rows from the old value.
        $migrated = $pdo->exec("UPDATE purchase_orders SET status = 'reviewed' WHERE status = 'review'");
        echo "  + Migrated $migrated row(s) from status='review' to status='reviewed'.\n";

        // Drop the legacy 'review' value from the enum.
        $pdo->exec("
            ALTER TABLE purchase_orders MODIFY COLUMN status
            ENUM('draft','pending','reviewed','approved','ordered','partially_received','received','cancelled','completed')
            NOT NULL DEFAULT 'pending'
        ");
        echo "  + Removed legacy 'review' value from status ENUM.\n";
    } else {
        echo "  · status ENUM already canonical (has 'reviewed').\n";
    }

    // ── 3. Permission row sanity check ───────────────────────────────────
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'purchase_orders' LIMIT 1")
                ->fetchColumn();

    if (!$perm) {
        echo "  ! permissions row for 'purchase_orders' is missing — skipping role grants.\n";
    } else {
        // Grant can_review + can_approve to Admin (role_id=1) and Managing
        // Director (role_id=2) — only if their role_permissions row exists
        // (we never INSERT a missing role row from a migration).
        $upd = $pdo->prepare("
            UPDATE role_permissions
            SET can_review  = 1,
                can_approve = 1
            WHERE permission_id = ?
              AND role_id IN (1, 2)
        ");
        $upd->execute([$perm]);
        echo "  + Granted can_review + can_approve on purchase_orders to roles 1 (Admin) and 2 (Managing Director) where present (" . $upd->rowCount() . " row(s)).\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
