<?php
/**
 * Sales Order Three-Approval Workflow normalization.
 *
 * 1. Add 'reviewed' to `sales_orders.status` enum (positioned between
 *    'pending' and 'approved'). Existing enum values are preserved.
 * 2. Add audit columns if missing:
 *      reviewed_by INT, reviewed_by_name VARCHAR(150), reviewed_by_role VARCHAR(100), reviewed_at DATETIME,
 *      approved_by_name VARCHAR(150), approved_by_role VARCHAR(100), approved_at DATETIME.
 *    (approved_by INT already exists.)
 * 3. Grant can_review + can_approve on page_key='sales_orders' to Admin (role_id=1)
 *    and Managing Director (role_id=2) where role_permissions rows exist.
 *
 * Idempotent — safe to re-run.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: SO three-approval workflow normalization...\n";

try {
    // ── 1. Add audit columns if missing ──────────────────────────────────
    $cols = [
        'reviewed_by'       => "ALTER TABLE sales_orders ADD COLUMN reviewed_by INT NULL AFTER approved_by",
        'reviewed_by_name'  => "ALTER TABLE sales_orders ADD COLUMN reviewed_by_name VARCHAR(150) NULL AFTER reviewed_by",
        'reviewed_by_role'  => "ALTER TABLE sales_orders ADD COLUMN reviewed_by_role VARCHAR(100) NULL AFTER reviewed_by_name",
        'reviewed_at'       => "ALTER TABLE sales_orders ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by_role",
        'approved_by_name'  => "ALTER TABLE sales_orders ADD COLUMN approved_by_name VARCHAR(150) NULL AFTER approved_by",
        'approved_by_role'  => "ALTER TABLE sales_orders ADD COLUMN approved_by_role VARCHAR(100) NULL AFTER approved_by_name",
        'approved_at'       => "ALTER TABLE sales_orders ADD COLUMN approved_at DATETIME NULL AFTER approved_by_role",
    ];
    foreach ($cols as $colName => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE '$colName'")->fetch(PDO::FETCH_ASSOC);
        if (!$exists) {
            $pdo->exec($sql);
            echo "  + Added column sales_orders.$colName.\n";
        } else {
            echo "  · Column sales_orders.$colName already exists.\n";
        }
    }

    // ── 2. Add 'reviewed' to status enum (between pending and approved) ──
    $statusCol = $pdo->query("SHOW COLUMNS FROM sales_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusCol && strpos($statusCol['Type'], "'reviewed'") === false) {
        $pdo->exec("
            ALTER TABLE sales_orders MODIFY COLUMN status
            ENUM('draft','pending','reviewed','approved','processing','shipped','delivered','completed','cancelled')
            NOT NULL DEFAULT 'draft'
        ");
        echo "  + Added 'reviewed' to sales_orders.status ENUM.\n";
    } else {
        echo "  · status ENUM already canonical (has 'reviewed').\n";
    }

    // ── 3. Permission grants ─────────────────────────────────────────────
    $perm = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'sales_orders' LIMIT 1")
                ->fetchColumn();

    if (!$perm) {
        echo "  ! permissions row for 'sales_orders' is missing — skipping role grants.\n";
    } else {
        $upd = $pdo->prepare("
            UPDATE role_permissions
            SET can_review  = 1,
                can_approve = 1
            WHERE permission_id = ?
              AND role_id IN (1, 2)
        ");
        $upd->execute([$perm]);
        echo "  + Granted can_review + can_approve on sales_orders to roles 1 (Admin) and 2 (Managing Director) where present (" . $upd->rowCount() . " row(s)).\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
