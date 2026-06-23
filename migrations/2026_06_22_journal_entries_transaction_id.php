<?php
/**
 * 2026_06_22_journal_entries_transaction_id.php
 * ---------------------------------------------
 * Adds the missing `transaction_id` column to `journal_entries`.
 *
 * The manual-journal endpoints (`api/account/save_journal.php`,
 * `add_compound_journal.php`, `update_journal.php`) all run
 * `UPDATE journal_entries SET transaction_id = ?` to store the link to the
 * `books_transactions` mirror, but the column does not exist — so every manual
 * journal create/edit threw "Unknown column 'transaction_id'" and rolled back
 * (the Journal page could not save). This adds the nullable link column.
 * Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add transaction_id to journal_entries...\n";

try {
    $has = $pdo->query("SHOW COLUMNS FROM journal_entries LIKE 'transaction_id'")->fetch();
    if ($has) {
        echo "  journal_entries.transaction_id already exists — nothing to do.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    $pdo->exec("ALTER TABLE journal_entries ADD COLUMN transaction_id INT NULL AFTER status");
    echo "  Added journal_entries.transaction_id (INT NULL).\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
