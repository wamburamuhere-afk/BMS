<?php
/**
 * 2026_06_11_reconcile_account_balances.php
 * -----------------------------------------
 * Recomputes accounts.current_balance from the posted general ledger so the
 * stored figure matches reality everywhere it is read (Chart of Accounts,
 * Bank Accounts, reports, payment dropdowns).
 *
 * balance = opening_balance + net posted movements on the account's natural side,
 * using the unified ledger view (items where present, else the entry header) from
 * core/account_balance.php.
 *
 * Idempotent: only writes rows that are actually out of sync; safe to re-run.
 * Criteria-based — works on any host's live data, no hard-coded ids.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/account_balance.php';
global $pdo;

echo "Starting migration: reconcile account balances to the posted ledger...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'accounts'")->fetch()) {
        echo "  ~ accounts table absent — nothing to reconcile.\n\nMigration complete.\n";
        exit(0);
    }

    $res = reconcileAccountBalances($pdo);
    echo "  + Checked {$res['total']} account(s); corrected {$res['changed']} drifted balance(s).\n";

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
