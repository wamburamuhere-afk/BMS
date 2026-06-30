<?php
/**
 * 2026_06_29_bank_recon_adjustments_table.php
 * ---------------------------------------------
 * Creates bank_reconciliation_adjustments — stores every adjusting journal
 * entry made during a reconciliation (bank charges, interest, NSF, standing
 * orders, or entries created from unmatched statement lines). Each row links
 * to the reconciliation header AND to the journal_entry that was posted, so
 * the adjustment can be reversed cleanly.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS.
 */
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: bank_recon_adjustments_table...\n";

try {
    // No FK constraints: bank_reconciliations is MyISAM (can't be a FK target)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bank_reconciliation_adjustments (
            adjustment_id     INT           NOT NULL AUTO_INCREMENT,
            reconciliation_id INT           NOT NULL,
            type              VARCHAR(30)   NOT NULL DEFAULT 'other',
            amount            DECIMAL(15,2) NOT NULL,
            gl_account_id     INT           NOT NULL,
            journal_entry_id  INT           NULL,
            memo              VARCHAR(500)  NULL,
            adjustment_date   DATE          NOT NULL,
            created_by        INT           NOT NULL,
            created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (adjustment_id),
            KEY idx_bra_recon (reconciliation_id),
            KEY idx_bra_je    (journal_entry_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "  + bank_reconciliation_adjustments table ready.\n";

    echo "\nMigration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
