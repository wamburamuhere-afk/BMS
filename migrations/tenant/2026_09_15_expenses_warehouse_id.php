<?php
/**
 * migrations/tenant/2026_09_15_expenses_warehouse_id.php
 *
 * `expenses` had no way to tie a cost to a specific shop — only `project_id`.
 * This blocked the Simple POS dashboard chart from ever showing "expenses per
 * shop" alongside Sales/Cost of Goods, since there was no column to scope by.
 *
 * Adds `expenses.warehouse_id INT NULL`, mirroring `pos_sales.warehouse_id`
 * exactly (nullable, no FK — same convention already used there and on
 * `journal_entries.warehouse_id`). NULL stays the correct value for a
 * company-wide expense (head-office rent, a shared subscription) — same
 * discipline `project_id` already follows on this table. Existing rows are
 * left NULL; nothing retroactively guesses which shop an old expense belongs
 * to.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: expenses.warehouse_id...\n";

try {
    $exists = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'warehouse_id'")->fetch();
    if ($exists) {
        echo "  · expenses.warehouse_id already present.\n";
    } else {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN warehouse_id INT DEFAULT NULL AFTER project_id");
        echo "  + expenses.warehouse_id added.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
