<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: register received_invoices permission + role assignments...\n";

try {
    // 1. Ensure the permission row exists
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['received_invoices']);
    $row = $existing->fetch();

    if (!$row) {
        $pdo->prepare("
            INSERT IGNORE INTO permissions (page_key, permission_name, page_name, module_name)
            VALUES (?, ?, ?, ?)
        ")->execute(['received_invoices', 'Received Invoices', 'Received Invoices', 'Finance']);
        echo "Permission row inserted.\n";
    } else {
        echo "Permission row already exists, skipping insert.\n";
    }

    // 2. Fetch the permission_id
    $pid = (int)$pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?")
        ->execute(['received_invoices']) ? $pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'received_invoices'")->fetchColumn() : 0;

    if (!$pid) { echo "Could not fetch permission_id, skipping role assignments.\n"; echo "Migration complete.\n"; exit(0); }

    // 3. Role assignments — full access for finance/management roles, view-only for others
    // Roles: 1=Admin, 2=Managing Director, 3=Loan Officer, 4=Staff,
    //        5=Director, 6=CFO, 7=Accountant, 8=Credit Manager, 9=Loan Manager, 11=Secretary
    $roles = $pdo->query("SELECT role_id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
    $full  = [1, 2, 5, 6, 7];  // Admin, MD, Director, CFO, Accountant — full CRUD + review + approve
    $basic = [3, 4, 8, 9, 11]; // Others — view + create only

    // Check if can_review / can_approve columns exist on this server
    $hasReview  = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_review'")->fetch();
    $hasApprove = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();

    foreach ($roles as $rid) {
        $rid  = (int)$rid;
        $isFull = in_array($rid, $full, true);

        $cols   = 'role_id, permission_id, can_view, can_create, can_edit, can_delete';
        $vals   = [$rid, $pid, 1, $isFull ? 1 : 0, $isFull ? 1 : 0, $isFull ? 1 : 0];
        $marks  = '?, ?, ?, ?, ?, ?';

        if ($hasReview)  { $cols .= ', can_review';  $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }
        if ($hasApprove) { $cols .= ', can_approve'; $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }

        $pdo->prepare("INSERT IGNORE INTO role_permissions ($cols) VALUES ($marks)")->execute($vals);
        echo "  Role $rid assigned.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
