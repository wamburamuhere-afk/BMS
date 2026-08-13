<?php
/**
 * Backfill: sub-contractor primary project -> sub_contractor_projects junction.
 *
 * Sub-contractors created or edited through the core Sub-Contractors form only
 * ever had their primary project written to `sub_contractors.project_id`; the
 * junction row was never inserted. Every project-side view reads the junction
 * table only (api/get_project_sub_contractors.php, project_view.php), so those
 * records were invisible inside the very project they belong to.
 *
 * The APIs now write the junction row at creation/edit time. This repairs the
 * records created before that fix. Criteria-based and idempotent — safe to
 * re-run; INSERT IGNORE + the unique key mean already-linked rows are untouched.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: backfill sub_contractor_projects from primary project_id...\n";

try {
    $hasSc = $pdo->query("SHOW TABLES LIKE 'sub_contractors'")->fetch();
    $hasScp = $pdo->query("SHOW TABLES LIKE 'sub_contractor_projects'")->fetch();
    if (!$hasSc || !$hasScp) {
        echo "sub_contractors / sub_contractor_projects not present on this server — nothing to backfill.\n";
        echo "Migration complete.\n";
        return;
    }

    $col = $pdo->query("SHOW COLUMNS FROM sub_contractors LIKE 'project_id'")->fetch();
    if (!$col) {
        echo "No project_id column on sub_contractors — nothing to backfill.\n";
        echo "Migration complete.\n";
        return;
    }

    // Report what is about to be repaired, so the deploy log names the records.
    $orphans = $pdo->query("
        SELECT s.supplier_id, s.supplier_name, s.project_id
        FROM sub_contractors s
        JOIN projects p ON p.project_id = s.project_id
        WHERE s.project_id IS NOT NULL
          AND s.status != 'deleted'
          AND NOT EXISTS (
              SELECT 1 FROM sub_contractor_projects x
              WHERE x.supplier_id = s.supplier_id AND x.project_id = s.project_id
          )
        ORDER BY s.supplier_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orphans)) {
        echo "No unlinked sub-contractors found — nothing to backfill.\n";
        echo "Migration complete.\n";
        return;
    }

    foreach ($orphans as $o) {
        echo "  linking #{$o['supplier_id']} {$o['supplier_name']} -> project {$o['project_id']}\n";
    }

    // Join `projects` so a stale project_id pointing at a deleted project cannot
    // create a dangling junction row.
    $inserted = $pdo->exec("
        INSERT IGNORE INTO sub_contractor_projects (supplier_id, project_id, assigned_by)
        SELECT s.supplier_id, s.project_id, s.created_by
        FROM sub_contractors s
        JOIN projects p ON p.project_id = s.project_id
        WHERE s.project_id IS NOT NULL
          AND s.status != 'deleted'
          AND NOT EXISTS (
              SELECT 1 FROM sub_contractor_projects x
              WHERE x.supplier_id = s.supplier_id AND x.project_id = s.project_id
          )
    ");

    echo "Linked $inserted sub-contractor(s) to their primary project.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
