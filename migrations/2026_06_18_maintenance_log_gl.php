<?php
/**
 * Migration: add expense_account_id + gl_journal_entry_id to maintenance_logs.
 *
 * expense_account_id — which GL expense account the cost is charged to
 *                      (e.g. "Vehicle Maintenance Expense", "Equipment Maintenance").
 * gl_journal_entry_id — the journal_entries.entry_id posted when the log is completed,
 *                        NULL until the log reaches completed status.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

echo "Migration: maintenance_log_gl\n";
echo str_repeat('-', 50) . "\n";

$cols = $pdo->query("SHOW COLUMNS FROM maintenance_logs")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('expense_account_id', $cols)) {
    $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN expense_account_id INT NULL AFTER notes");
    echo "Added expense_account_id column.\n";
} else {
    echo "expense_account_id already exists — skipped.\n";
}

if (!in_array('gl_journal_entry_id', $cols)) {
    $pdo->exec("ALTER TABLE maintenance_logs ADD COLUMN gl_journal_entry_id INT NULL AFTER expense_account_id");
    echo "Added gl_journal_entry_id column.\n";
} else {
    echo "gl_journal_entry_id already exists — skipped.\n";
}

echo "\nDone.\n";
