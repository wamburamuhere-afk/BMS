<?php
/**
 * 2026_07_08_clear_seed_department_leaders.php
 * --------------------------------------------
 * One-time baseline for the new Department Leadership feature.
 *
 * departments.manager_id / assistant_manager_id are read ONLY by the brand-new,
 * not-yet-deployed leadership feature (grep confirms nothing else consumes those
 * columns — api/update_reporting_line.php writes employees.reporting_to_id, not
 * departments.manager_id). Any value sitting there today is therefore a
 * pre-feature seed placeholder that the user never actually assigned, which was
 * surfacing as a phantom "leader" in the department-scoped Reporting To picker.
 *
 * This resets both columns to NULL so every department starts as "no leadership
 * set" — Reporting To then lists all employees of the department until a leader
 * is assigned through HR Actions → Department Leadership.
 *
 * Criteria-based + idempotent — a re-run is a no-op once all rows are NULL.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Clearing pre-feature seed department leaders...\n";

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM departments
                         WHERE manager_id IS NOT NULL OR assistant_manager_id IS NOT NULL");
    $before = (int)$stmt->fetchColumn();

    if ($before === 0) {
        echo "  Nothing to clear (no departments carry a leader/assistant).\n";
    } else {
        $pdo->exec("UPDATE departments
                    SET manager_id = NULL, assistant_manager_id = NULL
                    WHERE manager_id IS NOT NULL OR assistant_manager_id IS NOT NULL");
        echo "  Reset leadership on $before department(s) to NULL.\n";
    }

    echo "Done.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
