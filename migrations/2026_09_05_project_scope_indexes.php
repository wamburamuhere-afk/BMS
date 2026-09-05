<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

/**
 * Project-scope indexes — read paths only, no logic change.
 *
 * loadUserScope() (core/project_scope.php) runs once per login session
 * (header.php: `if (!isset($_SESSION['scope']))`) for every non-admin user,
 * and derives their accessible warehouses/suppliers/customers/employees with
 * four UNION queries filtered by `project_id IN (...)` across
 * purchase_orders, purchase_receipts, deliveries, stock_movements,
 * supplier_payments (via purchase_orders), invoices and sales_orders.
 *
 * Checked live: none of purchase_orders, purchase_receipts, invoices,
 * sales_orders or stock_movements carries an index on project_id — every one
 * of those branches is a full table scan. Because the result is cached in
 * $_SESSION['scope'] for the rest of the session, this cost is paid exactly
 * once per login, which is why it presented as "slow to log in, instant on
 * refresh" and why an account with zero project assignments (skips the
 * queries entirely via `if (!empty($projects))`) never showed it.
 *
 * Pure `ALTER TABLE ... ADD KEY`, guarded by SHOW INDEX so the file is safe
 * to re-run — same shape as 2026_08_21_query_perf_indexes.php.
 */

echo "Starting migration: project-scope indexes...\n";

/** Add an index only when a key of that name is not already present. */
$addIndex = function (PDO $pdo, string $table, string $keyName, string $columns): void {
    $exists = $pdo->query(
        "SHOW INDEX FROM `$table` WHERE Key_name = " . $pdo->quote($keyName)
    )->fetch();

    if ($exists) {
        echo "  · $table.$keyName already exists, skipping.\n";
        return;
    }

    $pdo->exec("ALTER TABLE `$table` ADD KEY `$keyName` ($columns)");
    echo "  + added $table.$keyName on ($columns).\n";
};

try {
    // Each of these feeds a `WHERE project_id IN (...)` (or, for
    // purchase_receipts, is joined against a project_id-filtered
    // purchase_orders) inside loadUserScope()'s derived-set queries.
    $addIndex($pdo, 'purchase_orders',   'ix_po_project',   '`project_id`');
    $addIndex($pdo, 'purchase_receipts', 'ix_pr_project',   '`project_id`');
    $addIndex($pdo, 'stock_movements',   'ix_sm_project',   '`project_id`');
    $addIndex($pdo, 'invoices',          'ix_inv_project',  '`project_id`');
    $addIndex($pdo, 'sales_orders',      'ix_so_project',   '`project_id`');

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
