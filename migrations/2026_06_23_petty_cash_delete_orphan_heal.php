<?php
/**
 * 2026_06_23_petty_cash_delete_orphan_heal.php
 * --------------------------------------------
 * Heals petty-cash GL orphaned by the OLD bare `DELETE FROM petty_cash_transactions`
 * (before delete_transaction.php reversed the ledger). Any legacy `transactions` row
 * of type 'petty_cash' / 'petty_cash_topup' whose petty_cash_transactions parent no
 * longer exists is reversed with the matching method:
 *   - 'petty_cash'        (expense) → reverseOutflow        (undo the source leg)
 *   - 'petty_cash_topup'  (deposit) → reverseJournalBalances (undo both legs)
 * Each restores account balances, unmirrors the journal_entries entry, and removes the
 * legacy transactions/books_transactions rows.
 *
 * Criteria-based + idempotent: a reversed orphan's `transactions` row is deleted, so a
 * re-run finds nothing. DML only — transaction is fine. Balance-checked.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';     // reversePettyCashLedger
require_once __DIR__ . '/../core/financial_reports.php';  // assertLedgerBalanced
global $pdo;

echo "Starting migration: heal orphaned petty-cash GL...\n";

try {
    $rows = $pdo->query("
        SELECT t.transaction_id, t.transaction_type
          FROM transactions t
         WHERE t.transaction_type IN ('petty_cash','petty_cash_topup')
           AND NOT EXISTS (SELECT 1 FROM petty_cash_transactions p WHERE p.transaction_id = t.transaction_id)
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  Found " . count($rows) . " orphaned petty-cash txn(s).\n";

    $pdo->beginTransaction();
    $healed = 0;
    foreach ($rows as $r) {
        $type = ($r['transaction_type'] === 'petty_cash_topup') ? 'deposit' : 'expense';
        reversePettyCashLedger($pdo, $type, (int)$r['transaction_id']);
        $healed++;
        echo "  + reversed txn #{$r['transaction_id']} ({$r['transaction_type']})\n";
    }
    $pdo->commit();

    $bal = assertLedgerBalanced($pdo, date('Y-m-d'));
    echo "  Ledger balanced after heal: " . (!empty($bal['ledger_balanced']) ? 'YES' : 'NO') . "\n";

    echo "\nMigration complete. Healed $healed orphan(s).\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
