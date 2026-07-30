<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

/**
 * 2026_07_30_remove_recurring_billing.php
 * -----------------------------------------
 * Removes the recurring-documents feature (Plan C, introduced in
 * 2026_06_10_recurring.php / 2026_06_25_expenses_recurring_profile_id.php).
 * The generator, its 2 CRUD/status API endpoints, the page, and the daily
 * cron trigger were all removed from the codebase in this same change —
 * this migration removes the storage that backed them.
 *
 * The two original creation migrations are left untouched (deployed
 * migration history is a one-way log, same principle as the GL itself —
 * this migration IS the record of the reversal, not a rewrite of the past).
 *
 * Confirmed before writing this: expenses.recurring_profile_id is metadata
 * only (its own migration's docblock says so — "never read by any GL
 * posting function"), and only 2 profiles ever existed, both already
 * status='ended'. No live/GL-relevant data is affected.
 *
 * Idempotent: every step checks existence first.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: remove recurring billing (tables + column)...\n";

try {
    // 1. expenses.recurring_profile_id — metadata-only FK column.
    $col = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'recurring_profile_id'")->fetch();
    if ($col) {
        $pdo->exec("ALTER TABLE expenses DROP COLUMN recurring_profile_id");
        echo "  - dropped expenses.recurring_profile_id.\n";
    } else {
        echo "  = expenses.recurring_profile_id already absent — skipping.\n";
    }

    // 2. recurring_runs (audit/idempotency trail — child-like table, drop first).
    if ($pdo->query("SHOW TABLES LIKE 'recurring_runs'")->fetch()) {
        $pdo->exec("DROP TABLE recurring_runs");
        echo "  - dropped table recurring_runs.\n";
    } else {
        echo "  = table recurring_runs already absent — skipping.\n";
    }

    // 3. recurring_profiles (the template + schedule table).
    if ($pdo->query("SHOW TABLES LIKE 'recurring_profiles'")->fetch()) {
        $pdo->exec("DROP TABLE recurring_profiles");
        echo "  - dropped table recurring_profiles.\n";
    } else {
        echo "  = table recurring_profiles already absent — skipping.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
