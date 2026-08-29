<?php
/**
 * 2026_08_29_company_calendar_permission.php
 * --------------------------------------------
 * app/bms/pos/company_calendar.php (Working Days + Public Holidays) is new —
 * seed its permission from each role's existing 'leave_types' grant, the
 * closest sibling (both configure how leave day-counting behaves). Idempotent:
 * skips the permission insert if it already exists; skips a role's seed row if
 * that (role_id, permission_id) pair already exists.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: company_calendar permission...\n";

try {
    $existing = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
    $existing->execute(['company_calendar']);
    $permId = $existing->fetchColumn();

    if ($permId) {
        echo "  · 'company_calendar' permission already exists (id {$permId}) — skipping insert.\n";
    } else {
        $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([
                'Company Calendar', 'company_calendar', 'Working Days & Holidays',
                'Company working-days configuration and public holiday calendar, used by leave day-counting',
                'Human Resources',
            ]);
        $permId = (int)$pdo->lastInsertId();
        echo "  + 'company_calendar' permission created (id {$permId}).\n";
    }

    $sourcePermId = (int)$pdo->query("SELECT permission_id FROM permissions WHERE page_key = 'leave_types'")->fetchColumn();
    if (!$sourcePermId) {
        echo "  ! 'leave_types' permission not found — cannot seed role grants. Migration complete.\n";
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
    echo "  + seeded company_calendar grants for {$seeded} role(s) from their current leave_types grant.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
