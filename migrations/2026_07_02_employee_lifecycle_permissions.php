<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: register employee_lifecycle permission + role assignments...\n";

try {
    // 1. Ensure the permission row exists
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['employee_lifecycle']);
    $row = $existing->fetch();

    if (!$row) {
        $pdo->prepare("
            INSERT IGNORE INTO permissions (page_key, permission_name, page_name, module_name)
            VALUES (?, ?, ?, ?)
        ")->execute(['employee_lifecycle', 'HR Actions', 'HR Actions', 'Human Resources']);
        echo "Permission row inserted.\n";
    } else {
        echo "Permission row already exists, skipping insert.\n";
    }

    // 2. Fetch the permission_id
    $stmt = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $stmt->execute(['employee_lifecycle']);
    $pid = (int)$stmt->fetchColumn();

    if (!$pid) { echo "Could not fetch permission_id, skipping role assignments.\n"; echo "Migration complete.\n"; exit(0); }

    // 3. Role assignments — resolved at runtime, never hard-coded ids (live-system rule).
    //    Full access (CRUD + review/approve) = admin-flagged roles + whichever roles
    //    currently hold can_edit on 'employees' (the HR-capable roles on this server).
    //    Everyone else = view-only.
    $fullRoles = $pdo->query("
        SELECT DISTINCT r.role_id
        FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.role_id
        LEFT JOIN permissions p ON p.permission_id = rp.permission_id AND p.page_key = 'employees'
        WHERE r.is_admin = 1 OR (p.page_key = 'employees' AND rp.can_edit = 1)
    ")->fetchAll(PDO::FETCH_COLUMN);
    $fullRoles = array_map('intval', $fullRoles);

    // Check workflow-verb columns exist on this server before writing them
    $hasReview  = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_review'")->fetch();
    $hasApprove = (bool)$pdo->query("SHOW COLUMNS FROM role_permissions LIKE 'can_approve'")->fetch();

    $roles = $pdo->query("SELECT role_id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($roles as $rid) {
        $rid    = (int)$rid;
        $isFull = in_array($rid, $fullRoles, true);

        $cols  = 'role_id, permission_id, can_view, can_create, can_edit, can_delete';
        $vals  = [$rid, $pid, 1, $isFull ? 1 : 0, $isFull ? 1 : 0, $isFull ? 1 : 0];
        $marks = '?, ?, ?, ?, ?, ?';

        if ($hasReview)  { $cols .= ', can_review';  $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }
        if ($hasApprove) { $cols .= ', can_approve'; $marks .= ', ?'; $vals[] = $isFull ? 1 : 0; }

        $pdo->prepare("INSERT IGNORE INTO role_permissions ($cols) VALUES ($marks)")->execute($vals);
        echo "  Role $rid assigned" . ($isFull ? " (full)" : " (view-only)") . ".\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
