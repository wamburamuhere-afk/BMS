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
    // Order-independent: only touch the leader columns that actually exist yet.
    // If this runs before 2026_07_08_department_leadership.php (filename sorts
    // "clear…" before "department…"), assistant_manager_id isn't there yet — the
    // schema migration then adds it defaulting to NULL, so the end state is still
    // "no leadership set" either way.
    $hasMgr  = $pdo->query("SHOW COLUMNS FROM departments LIKE 'manager_id'")->rowCount() > 0;
    $hasAsst = $pdo->query("SHOW COLUMNS FROM departments LIKE 'assistant_manager_id'")->rowCount() > 0;

    $conds = [];
    $sets  = [];
    if ($hasMgr)  { $conds[] = "manager_id IS NOT NULL";           $sets[] = "manager_id = NULL"; }
    if ($hasAsst) { $conds[] = "assistant_manager_id IS NOT NULL"; $sets[] = "assistant_manager_id = NULL"; }

    if (!$sets) {
        echo "  No leader columns present yet — nothing to clear.\n";
    } else {
        $where  = implode(' OR ', $conds);
        $before = (int)$pdo->query("SELECT COUNT(*) FROM departments WHERE $where")->fetchColumn();
        if ($before === 0) {
            echo "  Nothing to clear (no departments carry a leader/assistant).\n";
        } else {
            $pdo->exec("UPDATE departments SET " . implode(', ', $sets) . " WHERE $where");
            echo "  Reset leadership on $before department(s) to NULL.\n";
        }
    }

    echo "Done.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
