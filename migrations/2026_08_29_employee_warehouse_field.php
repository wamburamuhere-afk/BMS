<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: employees.warehouse_id (optional physical assignment)...\n";

/**
 * Adds an optional warehouse assignment to employees — the equivalent of what
 * the reference LMS calls "Branch", built on BMS's existing warehouses table
 * instead of introducing a new branches concept. Follows the same
 * Project -> Warehouse cascade already used in Procurement/Sales
 * (core/warehouse_scope.php + assets/js/warehouse-project-filter.js):
 * a project selected narrows the list to that project's warehouses; no
 * project shows only warehouses not linked to any project.
 *
 * Nullable, no default — an employee with no warehouse assigned is the normal
 * case, not an error state. No FK constraint, matching every other
 * warehouse_id column in this codebase (soft-delete convention — see
 * .claude/security.md §12 — makes a hard FK to a status='deleted' row awkward).
 */
try {
    $cols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('warehouse_id', $cols, true)) {
        echo "  · warehouse_id already exists — skipped.\n";
    } else {
        $pdo->exec("ALTER TABLE employees ADD COLUMN warehouse_id INT NULL AFTER project_id");
        echo "  + warehouse_id added.\n";
    }

    $idx = $pdo->query("SHOW INDEX FROM employees")->fetchAll(PDO::FETCH_COLUMN, 2);
    if (in_array('idx_employees_warehouse', $idx, true)) {
        echo "  · index idx_employees_warehouse already exists — skipped.\n";
    } else {
        $pdo->exec("ALTER TABLE employees ADD INDEX idx_employees_warehouse (warehouse_id)");
        echo "  + index idx_employees_warehouse added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
