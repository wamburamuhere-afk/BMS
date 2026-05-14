<?php
// Migration: Sub-Contractor Many-to-Many Projects
// Creates junction table and migrates existing project_id data
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: sub_contractor_projects...\n";

try {
    $pdo->beginTransaction();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sub_contractor_projects` (
            `id`          INT AUTO_INCREMENT PRIMARY KEY,
            `supplier_id` INT NOT NULL,
            `project_id`  INT NOT NULL,
            `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `assigned_by` INT NULL,
            UNIQUE KEY `unique_sc_project` (`supplier_id`, `project_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ Table 'sub_contractor_projects' created.\n";

    // Migrate existing single project_id assignments
    $migrated = $pdo->exec("
        INSERT IGNORE INTO sub_contractor_projects (supplier_id, project_id)
        SELECT supplier_id, project_id
        FROM sub_contractors
        WHERE project_id IS NOT NULL
    ");
    echo "✓ Migrated $migrated existing project assignments.\n";

    $pdo->commit();
    echo "Migration complete.\n";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
