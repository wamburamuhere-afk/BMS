<?php
/**
 * 2026_06_29_bank_recon_opening_balance.php
 * ------------------------------------------
 * Adds opening_balance to bank_reconciliations so each new reconciliation can
 * carry forward the adjusted_balance from the prior finalized period for the
 * same account (Phase 3 beginning-balance chain).
 *
 * Idempotent: skipped if the column already exists.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: bank_recon_opening_balance...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM bank_reconciliations LIKE 'opening_balance'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE bank_reconciliations
                    ADD COLUMN opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00
                    AFTER adjusted_balance");
        echo "  + Added opening_balance column to bank_reconciliations.\n";
    } else {
        echo "  ~ opening_balance already exists — skipped.\n";
    }

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
