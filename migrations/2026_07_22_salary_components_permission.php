<?php
/**
 * 2026_07_22_salary_components_permission.php
 * ---------------------------------------------
 * Salary Components (app/bms/pos/salary_components.php) has always piggybacked
 * on the 'payroll' permission — it has no page_key of its own, so it cannot be
 * granted or revoked independently of Payroll in user_roles.php (there's no
 * checkbox row for it at all). Editing salary structures (allowances,
 * deductions, tax bands) is arguably more sensitive than viewing a payroll run,
 * so it deserves its own gate.
 *
 * This migration only ADDS the new permission row and seeds it from each
 * role's EXISTING 'payroll' grant, so nobody loses access on deploy — the
 * page/API code change (in the same PR) is what actually starts enforcing
 * 'salary_components' instead of 'payroll'. An admin can narrow individual
 * roles afterward via the normal user_roles.php UI.
 *
 * Idempotent: skips the permission insert if the page_key already exists;
 * skips the role-permission seed for any (role_id) that already has a
 * salary_components row (so re-running never clobbers an admin's later edit).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: salary_components permission...\n";

try {
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['salary_components']);
    $scPermId = $existing->fetchColumn();

    if ($scPermId) {
        echo "  · 'salary_components' permission already exists (id {$scPermId}) — skipping insert.\n";
    } else {
        $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([
                'Salary Components', 'salary_components', 'Salary Components',
                'Reusable allowances/deductions/bonuses used to build employee salary structures',
                'Human Resources',
            ]);
        $scPermId = (int)$pdo->lastInsertId();
        echo "  + 'salary_components' permission created (id {$scPermId}).\n";
    }

    $payrollPermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'payroll'")->fetchColumn();
    if (!$payrollPermId) {
        echo "  ! 'payroll' permission not found — cannot seed role grants. Migration complete.\n";
        return;
    }

    $roleRows = $pdo->prepare("SELECT role_id, can_view, can_create, can_edit, can_delete, can_review, can_approve
                                  FROM role_permissions WHERE permission_id = ?");
    $roleRows->execute([$payrollPermId]);
    $payrollGrants = $roleRows->fetchAll(PDO::FETCH_ASSOC);

    $hasRow = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $insert = $pdo->prepare("INSERT INTO role_permissions
                                (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $seeded = 0;
    foreach ($payrollGrants as $g) {
        $hasRow->execute([$g['role_id'], $scPermId]);
        if ($hasRow->fetchColumn()) continue; // already has its own salary_components row — leave it alone
        $insert->execute([
            $g['role_id'], $scPermId,
            $g['can_view'], $g['can_create'], $g['can_edit'], $g['can_delete'], $g['can_review'], $g['can_approve'],
        ]);
        $seeded++;
    }
    echo "  + seeded salary_components grants for {$seeded} role(s) from their current payroll grant.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
