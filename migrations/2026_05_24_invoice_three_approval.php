<?php
/**
 * Invoice Three-Approval Workflow normalization.
 *
 * The invoices table already has:
 *   - status ENUM('pending','reviewed','approved','paid','partial') DEFAULT 'pending'
 *   - reviewed_by INT, approved_by INT
 *
 * What this migration adds:
 *   - The 6 *_by_name / *_by_role / *_at audit columns used by the canonical
 *     three_approval.md signature row + audit panel.
 *   - can_review + can_approve grants for Admin (role_id=1) and Managing
 *     Director (role_id=2).
 *
 * Idempotent — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Invoice three-approval workflow normalization...\n";

try {
    // ── 1. Add audit columns if missing ──────────────────────────────────
    $cols = [
        'reviewed_by_name'  => "ALTER TABLE invoices ADD COLUMN reviewed_by_name VARCHAR(150) NULL AFTER reviewed_by",
        'reviewed_by_role'  => "ALTER TABLE invoices ADD COLUMN reviewed_by_role VARCHAR(100) NULL AFTER reviewed_by_name",
        'reviewed_at'       => "ALTER TABLE invoices ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_role",
        'approved_by_name'  => "ALTER TABLE invoices ADD COLUMN approved_by_name VARCHAR(150) NULL AFTER approved_by",
        'approved_by_role'  => "ALTER TABLE invoices ADD COLUMN approved_by_role VARCHAR(100) NULL AFTER approved_by_name",
        'approved_at'       => "ALTER TABLE invoices ADD COLUMN approved_at DATETIME NULL AFTER approved_by_role",
    ];
    foreach ($cols as $colName => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM invoices LIKE '$colName'")->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            $pdo->exec($sql);
            echo "  + Added column invoices.$colName.\n";
        } else {
            echo "  · Column invoices.$colName already exists.\n";
        }
    }

    // ── 2. Permission grants ─────────────────────────────────────────────
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'invoices' LIMIT 1")
                ->fetchColumn();

    if (!$perm) {
        echo "  ! permissions row for 'invoices' is missing — skipping role grants.\n";
    } else {
        $upd = $pdo->prepare("
            UPDATE role_permissions
            SET can_review  = 1,
                can_approve = 1
            WHERE permission_id = ?
              AND role_id IN (1, 2)
        ");
        $upd->execute([$perm]);
        echo "  + Granted can_review + can_approve on invoices to roles 1 (Admin) and 2 (Managing Director) where present (" . $upd->rowCount() . " row(s)).\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
