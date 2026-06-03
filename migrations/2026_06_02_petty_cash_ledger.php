<?php
/**
 * 2026_06_02_petty_cash_ledger.php
 * --------------------------------
 * Adds petty_cash_transactions.transaction_id so a petty-cash expense (an
 * "out" transaction) can post the consolidated outflow (Dr Accounts Payable,
 * Cr Petty Cash) and reverse cleanly. Petty cash is the imprest float — its
 * source is always the Petty Cash account (no Paid-From dropdown). Idempotent.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: petty_cash_transactions.transaction_id...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'petty_cash_transactions'")->fetch()) {
        echo "  ! petty_cash_transactions not found — skipping.\n";
        exit(0);
    }
    if ($pdo->query("SHOW COLUMNS FROM petty_cash_transactions LIKE 'transaction_id'")->fetch()) {
        echo "  · transaction_id already exists, skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE petty_cash_transactions ADD COLUMN transaction_id INT NULL AFTER reference_number");
        echo "  + added petty_cash_transactions.transaction_id.\n";
    }
    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
