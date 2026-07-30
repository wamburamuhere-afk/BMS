<?php
/**
 * 2026_07_30_bank_transactions_matched_pair.php
 * -----------------------------------------------
 * Bank Reconciliation Phase 5 — Statement Import + Pairing.
 *
 * Adds bank_transactions.matched_transaction_id: when a statement-side row
 * (imported_from IS NOT NULL) is confirmed against a book-side row
 * (imported_from IS NULL) for the same real-world movement, both rows point
 * at each other so the pair is always matched/unmatched together — this is
 * what prevents the same economic event being counted twice (once via its
 * book-side auto-write, once via its imported statement line) in the
 * uncleared_movement total that get_reconciliation_lines.php computes.
 *
 * No FK constraint: bank_transactions is MyISAM (same reason
 * bank_reconciliation_adjustments has none — see 2026_06_29 migration).
 *
 * Idempotent: checks SHOW COLUMNS before ALTER.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: bank_transactions_matched_pair...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'bank_transactions'")->fetch()) {
        echo "  ! bank_transactions table not found — skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $col = $pdo->query("SHOW COLUMNS FROM bank_transactions LIKE 'matched_transaction_id'")->fetch();
    if (!$col) {
        $pdo->exec("
            ALTER TABLE bank_transactions
            ADD COLUMN matched_transaction_id INT NULL AFTER matching_reference,
            ADD KEY idx_bt_matched_txn (matched_transaction_id)
        ");
        echo "  + bank_transactions.matched_transaction_id added.\n";
    } else {
        echo "  = bank_transactions.matched_transaction_id already exists — skipping.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
