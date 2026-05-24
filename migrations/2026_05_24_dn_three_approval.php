<?php
/**
 * Delivery Notes — three-approval workflow normalization.
 *
 * Existing schema:
 *   deliveries.status ENUM('draft','review','approved','dispatched','delivered','cancelled')
 *   default 'draft'
 *   audit cols already present except reviewed_by INT
 *
 * What this migration does:
 *   1. Adds reviewed_by INT if missing.
 *   2. Expands enum to include 'pending' + 'reviewed' (alongside legacy values).
 *   3. Migrates rows: draft → pending, review → reviewed.
 *   4. Drops legacy 'draft' + 'review' from the enum; default becomes 'pending'.
 *   5. Grants can_review + can_approve on page_key='dn' to Admin + Managing Director.
 *
 * Idempotent — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: DN three-approval workflow normalization...\n";

try {
    // ── 1. Add reviewed_by INT if missing ────────────────────────────────
    $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'reviewed_by'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec("ALTER TABLE deliveries ADD COLUMN reviewed_by INT NULL AFTER reviewed_by_role");
        echo "  + Added column deliveries.reviewed_by INT.\n";
    } else {
        echo "  · Column deliveries.reviewed_by already exists.\n";
    }

    // ── 2-4. Status enum normalization ───────────────────────────────────
    $statusCol = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $hasLegacy = $statusCol && (strpos($statusCol['Type'], "'draft'") !== false || strpos($statusCol['Type'], "'review'") !== false && strpos($statusCol['Type'], "'reviewed'") === false);

    if ($hasLegacy) {
        // 2. Expand enum to include both old + new values so the UPDATE below
        //    doesn't trip MySQL's silent truncation.
        $pdo->exec("
            ALTER TABLE deliveries MODIFY COLUMN status
            ENUM('draft','pending','review','reviewed','approved','dispatched','delivered','cancelled')
            NOT NULL DEFAULT 'pending'
        ");
        echo "  + Expanded status ENUM to include pending + reviewed.\n";

        // 3. Migrate data
        $promotedDraft  = $pdo->exec("UPDATE deliveries SET status = 'pending'  WHERE status = 'draft'");
        $promotedReview = $pdo->exec("UPDATE deliveries SET status = 'reviewed' WHERE status = 'review'");
        echo "  + Promoted $promotedDraft draft row(s) to pending.\n";
        echo "  + Promoted $promotedReview review row(s) to reviewed.\n";

        // 4. Drop the legacy values from the enum
        $pdo->exec("
            ALTER TABLE deliveries MODIFY COLUMN status
            ENUM('pending','reviewed','approved','dispatched','delivered','cancelled')
            NOT NULL DEFAULT 'pending'
        ");
        echo "  + Removed legacy 'draft' + 'review' from ENUM; default is now 'pending'.\n";
    } else {
        echo "  · status ENUM already canonical.\n";
    }

    // ── 5. Permission grants ─────────────────────────────────────────────
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'dn' LIMIT 1")->fetchColumn();
    if (!$perm) {
        echo "  ! permissions row for 'dn' is missing — skipping role grants.\n";
    } else {
        $upd = $pdo->prepare("
            UPDATE role_permissions
            SET can_review  = 1,
                can_approve = 1
            WHERE permission_id = ?
              AND role_id IN (1, 2)
        ");
        $upd->execute([$perm]);
        echo "  + Granted can_review + can_approve on dn to roles 1 (Admin) and 2 (Managing Director) where present (" . $upd->rowCount() . " row(s)).\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
