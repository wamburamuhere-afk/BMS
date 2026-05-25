<?php
/**
 * Project-Scope Foundation Migration
 * ----------------------------------
 * Phase A of project_scope_implementation_plan.md. Creates the two
 * tables that back the new project-scope access-control axis:
 *
 *   - user_projects          (primary assignment, many-to-many user↔project)
 *   - user_scope_overrides   (optional cross-project resource grants)
 *
 * Default-deny posture: no rows are seeded. A user with no
 * user_projects rows sees nothing on scoped pages once Phase B ships.
 * Admin (`isAdmin()`) bypasses all scope checks regardless of these
 * tables.
 *
 * Idempotent — CREATE TABLE IF NOT EXISTS; re-runs are no-ops.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Project-Scope foundation tables...\n";

try {
    // ── 1. user_projects ────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_projects (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            user_id      INT NOT NULL,
            project_id   INT NOT NULL,
            assigned_by  INT NOT NULL,
            assigned_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_project (user_id, project_id),
            INDEX idx_user (user_id),
            INDEX idx_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  user_projects table ready.\n";

    // ── 2. user_scope_overrides ─────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_scope_overrides (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            user_id        INT NOT NULL,
            resource_type  ENUM('warehouse','supplier','customer','employee') NOT NULL,
            resource_id    INT NULL,
            granted_by     INT NOT NULL,
            granted_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_type (user_id, resource_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  user_scope_overrides table ready.\n";

    echo "Project-Scope foundation migration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
