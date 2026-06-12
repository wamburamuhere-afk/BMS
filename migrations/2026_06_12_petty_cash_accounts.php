<?php
/**
 * 2026_06_12_petty_cash_accounts.php
 * ----------------------------------
 * Adds the account links a petty cash transaction needs to post correctly to
 * the general ledger and to support multiple funds:
 *
 *   fund_account_id     — which petty cash fund the entry belongs to
 *   expense_account_id  — the EXPENSE account an expense is booked to (the real
 *                         "category": Dr expense / Cr petty cash). Replaces the
 *                         meaningless account_categories link for expenses.
 *   source_account_id   — the funding bank a deposit/top-up came from
 *
 * Idempotent: each column is added only if missing. No data is changed.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: petty cash account links...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'petty_cash_transactions'")->fetch()) {
        echo "  ~ petty_cash_transactions table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }

    $cols = [
        'fund_account_id'    => "ADD COLUMN `fund_account_id` INT NULL DEFAULT NULL AFTER `category_id`",
        'expense_account_id' => "ADD COLUMN `expense_account_id` INT NULL DEFAULT NULL AFTER `fund_account_id`",
        'source_account_id'  => "ADD COLUMN `source_account_id` INT NULL DEFAULT NULL AFTER `expense_account_id`",
    ];

    foreach ($cols as $name => $ddl) {
        $exists = $pdo->query("SHOW COLUMNS FROM `petty_cash_transactions` LIKE " . $pdo->quote($name))->fetch();
        if ($exists) {
            echo "  ~ {$name} already exists — skipping.\n";
            continue;
        }
        $pdo->exec("ALTER TABLE `petty_cash_transactions` $ddl");
        echo "  + {$name} column added.\n";
    }

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
