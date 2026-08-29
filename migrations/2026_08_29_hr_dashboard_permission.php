<?php
/**
 * 2026_08_29_hr_dashboard_permission.php
 * -----------------------------------------
 * app/bms/pos/hr_dashboard.php is new — a single HR command-center combining
 * headcount, contract/probation expiry (previously notification-only, no
 * visual surface anywhere), HR Actions/acknowledgment compliance, department
 * distribution, and recruitment pipeline. Seeded from each role's existing
 * 'hr_performance' grant (closest sibling: both are HR-wide visibility, not
 * tied to one employee). Idempotent.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: hr_dashboard permission...\n";

try {
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['hr_dashboard']);
    $permId = $existing->fetchColumn();

    if ($permId) {
        echo "  · 'hr_dashboard' permission already exists (id {$permId}) — skipping insert.\n";
    } else {
        $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([
                'HR Dashboard', 'hr_dashboard', 'HR Dashboard',
                'HR command centre: headcount, contract/probation expiry, HR Actions, department distribution, recruitment pipeline',
                'Human Resources',
            ]);
        $permId = (int)$pdo->lastInsertId();
        echo "  + 'hr_dashboard' permission created (id {$permId}).\n";
    }

    $sourcePermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'hr_performance'")->fetchColumn();
    if (!$sourcePermId) {
        echo "  ! 'hr_performance' permission not found — cannot seed role grants. Migration complete.\n";
        return;
    }

    $roleRows = $pdo->prepare("SELECT role_id, can_view, can_create, can_edit, can_delete, can_review, can_approve
                                  FROM role_permissions WHERE permission_id = ?");
    $roleRows->execute([$sourcePermId]);
    $grants = $roleRows->fetchAll(PDO::FETCH_ASSOC);

    $hasRow = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $insert = $pdo->prepare("INSERT INTO role_permissions
                                (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $seeded = 0;
    foreach ($grants as $g) {
        $hasRow->execute([$g['role_id'], $permId]);
        if ($hasRow->fetchColumn()) continue;
        $insert->execute([
            $g['role_id'], $permId,
            $g['can_view'], $g['can_create'], $g['can_edit'], $g['can_delete'], $g['can_review'], $g['can_approve'],
        ]);
        $seeded++;
    }
    echo "  + seeded hr_dashboard grants for {$seeded} role(s) from their current hr_performance grant.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
