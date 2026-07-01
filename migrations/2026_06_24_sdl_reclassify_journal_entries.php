<?php
/**
 * Migration: 2026_06_24_sdl_reclassify_journal_entries
 *
 * After P-6, postSdlAccrual() checks journal_entries using
 * entity_type='sdl_accrual' and entity_id=YYYYMM (e.g. 202606).
 *
 * Existing SDL journal_entries were posted via recordGlobalTransaction()
 * (old path), so they have entity_type='books_transaction' and
 * entity_id = the transactions.transaction_id. This migration updates
 * those rows to the canonical entity_type so the new idempotency check
 * finds them and does not double-post.
 *
 * Safe to run multiple times — the WHERE clause targets only rows that
 * still have entity_type='books_transaction' and are linked to an SDL
 * accrual transaction.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: SDL journal_entries reclassify...\n";

try {
    $affected = $pdo->exec("
        UPDATE journal_entries je
        INNER JOIN transactions t ON t.transaction_id = je.entity_id
        SET je.entity_type = 'sdl_accrual',
            je.entity_id   = CAST(
                REPLACE(REPLACE(t.reference_number, 'SDL-ACC-', ''), '-', '')
            AS UNSIGNED)
        WHERE je.entity_type    = 'books_transaction'
          AND t.transaction_type = 'sdl_accrual'
          AND t.reference_number LIKE 'SDL-ACC-%'
    ");
    echo "Rows reclassified: {$affected}\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
