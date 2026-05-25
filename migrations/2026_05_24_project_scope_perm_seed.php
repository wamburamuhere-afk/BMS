<?php
/**
 * Project-Scope — admin-UI permission key seed
 * --------------------------------------------
 * Phase A of project_scope_implementation_plan.md. Seeds the
 * `user_projects` permission key so the new admin UI at
 * app/constant/settings/user_projects.php can be gated by the existing
 * permission system (`autoEnforcePermission('user_projects')`).
 *
 * Default-deny posture: no role_permissions rows inserted; admin
 * always bypasses via `isAdmin()`. Non-admin roles must be granted
 * via /user_roles.php.
 *
 * Idempotent — INSERT IGNORE so re-runs are no-ops.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Project-Scope perm key seed...\n";

try {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO permissions (page_key, page_name, description, module_name)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        'user_projects',
        'User Project Assignments',
        'Manage which projects each user can access (project-scope access control)',
        'System Settings',
    ]);

    if ($stmt->rowCount() > 0) {
        echo "  Inserted 'user_projects' permission key.\n";
    } else {
        echo "  'user_projects' permission key already present (no-op).\n";
    }

    echo "Project-Scope perm seed migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
