<?php
/**
 * 2026_08_29_org_structure_permissions.php
 * -----------------------------------------
 * Departments, Designations and Employment Types have existed as lookup tables
 * since the HR foundation, populated only as a side-effect of the Employee
 * wizard's "Other (specify)" box — there was never an admin page to manage them,
 * so they never had their own permission gate either.
 *
 * New pages: app/bms/pos/departments.php, designations.php, employment_types.php
 * (page_keys: 'departments', 'designations', 'employment_types').
 *
 * Seeded from each role's EXISTING 'employees' grant (same reasoning as the
 * salary_components-from-payroll seed in 2026_07_22_salary_components_permission.php)
 * so nobody loses or gains access on deploy — whoever could already manage
 * Employees can manage the org-structure lookups Employees depends on. An admin
 * can narrow individual roles afterward via the normal user_roles.php UI.
 *
 * Idempotent: skips a permission insert if its page_key already exists; skips a
 * role's seed row if that (role_id, permission_id) pair already exists.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: org structure (departments/designations/employment_types) permissions...\n";

try {
    $pages = [
        'departments'       => ['Departments',       'Departments',       'Manage company departments, leadership and hierarchy'],
        'designations'      => ['Designations',       'Designations',      'Manage job designations / titles and their department + pay grade'],
        'employment_types'  => ['Employment Types',   'Employment Types',  'Manage employment type lookups (full time, contract, part time, etc.)'],
    ];

    $employeesPermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'employees'")->fetchColumn();
    if (!$employeesPermId) {
        echo "  ! 'employees' permission not found — cannot seed role grants.\n";
        exit(1);
    }

    $roleRows = $pdo->prepare("SELECT role_id, can_view, can_create, can_edit, can_delete, can_review, can_approve
                                  FROM role_permissions WHERE permission_id = ?");
    $roleRows->execute([$employeesPermId]);
    $employeeGrants = $roleRows->fetchAll(PDO::FETCH_ASSOC);

    $hasRow = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $insertGrant = $pdo->prepare("INSERT INTO role_permissions
                                (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($pages as $key => [$name, $pageName, $desc]) {
        $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
        $existing->execute([$key]);
        $permId = $existing->fetchColumn();

        if ($permId) {
            echo "  · '$key' permission already exists (id {$permId}) — skipping insert.\n";
        } else {
            $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
                           VALUES (?, ?, ?, ?, ?)")
                ->execute([$name, $key, $pageName, $desc, 'Human Resources']);
            $permId = (int)$pdo->lastInsertId();
            echo "  + '$key' permission created (id {$permId}).\n";
        }

        $seeded = 0;
        foreach ($employeeGrants as $g) {
            $hasRow->execute([$g['role_id'], $permId]);
            if ($hasRow->fetchColumn()) continue;
            $insertGrant->execute([
                $g['role_id'], $permId,
                $g['can_view'], $g['can_create'], $g['can_edit'], $g['can_delete'], $g['can_review'], $g['can_approve'],
            ]);
            $seeded++;
        }
        echo "    seeded '$key' grants for {$seeded} role(s) from their current 'employees' grant.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
