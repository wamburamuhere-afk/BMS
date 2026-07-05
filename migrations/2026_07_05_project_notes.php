<?php
/**
 * 2026_07_05_project_notes.php
 * ----------------------------
 * Real Project Notes storage (replaces the old hardcoded/no-op notes mockup).
 *
 * A `project_notes` table already exists on some installs with columns
 * (note_id, project_id, user_id, note_content, created_at, updated_at). This
 * migration:
 *   1. Creates the table (that same shape) if it does not exist yet.
 *   2. Adds a `status` column for soft-delete if it is missing.
 * Additive + idempotent — safe to re-run.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Ensuring project_notes table + status column...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS project_notes (
            note_id      INT AUTO_INCREMENT PRIMARY KEY,
            project_id   INT NOT NULL,
            user_id      INT NULL,
            note_content TEXT NOT NULL,
            status       VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project (project_id),
            INDEX idx_project_status (project_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Add status column on pre-existing tables that lack it
    $hasStatus = $pdo->query("SHOW COLUMNS FROM project_notes LIKE 'status'")->rowCount() > 0;
    if (!$hasStatus) {
        $pdo->exec("ALTER TABLE project_notes ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER note_content");
        echo "  Added status column.\n";
    }

    echo "Done.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
