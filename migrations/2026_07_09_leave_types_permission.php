<?php
/**
 * 2026_07_09_leave_types_permission.php
 * -------------------------------------
 * Leaves module — Phase 2: permission key for the Leave Types management page.
 *
 * The page (app/bms/pos/leave_types.php) is deliberately NOT in the header nav.
 * It is reached only from the "Manage leave types & maximum days" link rendered
 * beneath the Leave Type field on the leave form. It still needs a page_key so
 * canView/canCreate/canEdit/canDelete work and so an admin can grant it per role.
 *
 * Default-deny: no role_permissions rows are inserted. Admins bypass via isAdmin().
 * Idempotent (INSERT IGNORE on the unique page_key).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: leave_types permission key seed...\n";

try {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO permissions (page_key, page_name, description, module_name)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        'leave_types',
        'Leave Types',
        'Manage leave types, their maximum days per year and paid/unpaid status',
        'Human Resources',
    ]);

    if ($stmt->rowCount() > 0) {
        echo "  + Inserted 'leave_types' permission key.\n";
    } else {
        echo "  = 'leave_types' permission key already present (no-op).\n";
    }

    echo "Migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
