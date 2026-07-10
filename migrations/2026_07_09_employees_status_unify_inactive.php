<?php
/**
 * 2026_07_09_employees_status_unify_inactive.php
 * ------------------------------------------------
 * Employee inactivation plan (Phase 0) — see employee_inactivation_plan.md.
 *
 * `employees.status` ENUM('active','inactive','terminated') has carried an
 * unused 'inactive' member since it was added; nothing ever read or wrote it.
 * The only writer of this column, api/delete_employee.php's "Delete" action,
 * used 'terminated' as its soft-delete marker instead — a second, separate
 * "am I inactive" signal from `employment_status`, which the HR Action
 * termination/resignation workflow writes on its own and never kept in sync.
 *
 * Per plan decision D2: `status` becomes a simple active / not-active gate.
 * 'terminated' is retired as a status value in favour of 'inactive' — the
 * REASON (terminated, resigned, failed probation) lives in
 * `employment_status` + the inactivation reason text, not as a second status
 * bucket. This migration backfills existing rows; core/lifecycle_effects.php
 * and the new Inactivate action (Phase 1) are updated separately to only
 * ever write 'inactive' going forward.
 *
 * Additive + idempotent — a plain UPDATE, safe to run twice.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: employees.status 'terminated' -> 'inactive'...\n";

try {
    $before = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'terminated'")->fetchColumn();
    echo "  Found $before row(s) with status='terminated'.\n";

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE status = 'terminated'");
    $stmt->execute();
    $affected = $stmt->rowCount();
    $pdo->commit();

    echo "  Updated $affected row(s) to status='inactive'.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
