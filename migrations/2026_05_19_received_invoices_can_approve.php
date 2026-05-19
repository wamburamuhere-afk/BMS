<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: ensure can_approve column + set approve permissions for received_invoices...\n";

try {
    // 1. Add can_approve to role_permissions if missing
    $colExists = $pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();
    if (!$colExists) {
        $pdo->exec("ALTER TABLE role_permissions ADD COLUMN can_approve TINYINT(1) NOT NULL DEFAULT 0");
        echo "Column can_approve added to role_permissions.\n";
    } else {
        echo "Column can_approve already exists.\n";
    }

    // 2. Get the permission_id for received_invoices
    $pid = $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'received_invoices'")->fetchColumn();
    if (!$pid) {
        echo "Permission 'received_invoices' not found — skipping approve assignment.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    // 3. Set can_approve = 1 for finance/management roles, 0 for others
    // Roles that should be able to approve: Admin(1), MD(2), Director(5), CFO(6), Accountant(7)
    $approveRoles = [1, 2, 5, 6, 7];
    $roles = $pdo->query("SELECT role_id FROM roles")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($roles as $rid) {
        $rid       = (int)$rid;
        $canApprove = in_array($rid, $approveRoles, true) ? 1 : 0;

        // Only update rows that exist (INSERT IGNORE already handled by earlier migration)
        $existing = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?");
        $existing->execute([$rid, $pid]);
        if ($existing->fetch()) {
            $pdo->prepare("UPDATE role_permissions SET can_approve = ? WHERE role_id = ? AND permission_id = ?")
                ->execute([$canApprove, $rid, $pid]);
            echo "  Role $rid: can_approve = $canApprove (updated).\n";
        } else {
            echo "  Role $rid: no row in role_permissions for received_invoices — skipping.\n";
        }
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
