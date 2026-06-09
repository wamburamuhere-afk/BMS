<?php
/**
 * 2026_06_09_backfill_journal_from_books.php
 * ------------------------------------------
 * Gap 1 backfill: mirror every existing money-engine transaction (held in
 * books_transactions) into the canonical journal_entries ledger that the reports
 * and the Chart of Accounts read. Before this, those views were near-empty while
 * the real activity lived only in books_transactions.
 *
 * SAFE & idempotent:
 *  - Skips manual journals (transaction_type='journal' — they already write
 *    journal_entries themselves).
 *  - Skips any transaction already mirrored (entity_type='books_transaction').
 *  - Balance-neutral: does NOT touch accounts.current_balance.
 *  - Re-runnable: a second run creates 0 new entries.
 *  - Reversible: DELETE FROM journal_entries WHERE entity_type='books_transaction'
 *    (and its items) undoes it.
 */
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../api/helpers/transaction_helper.php';   // mirrorTransactionToJournal
global $pdo;

echo "Starting migration: backfill journal_entries from books_transactions...\n";

try {
    $sql = "
        SELECT t.transaction_id, t.transaction_date, t.description
          FROM transactions t
         WHERE EXISTS (SELECT 1 FROM books_transactions b WHERE b.transaction_id = t.transaction_id)
           AND (t.transaction_type IS NULL OR t.transaction_type <> 'journal')
           AND NOT EXISTS (SELECT 1 FROM journal_entries je
                            WHERE je.entity_type = 'books_transaction'
                              AND je.entity_id   = t.transaction_id)
         ORDER BY t.transaction_id
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo "  Candidates to mirror: " . count($rows) . "\n";

    $made = 0; $skipped = 0;
    foreach ($rows as $r) {
        $tid = (int)$r['transaction_id'];
        try {
            $eid = mirrorTransactionToJournal($pdo, $tid, $r['description'], $r['transaction_date'], null);
            if ($eid) { $made++; }
            else      { $skipped++; echo "  skipped txn $tid (unbalanced or <2 legs)\n"; }
        } catch (Throwable $e) {
            $skipped++;
            echo "  skipped txn $tid — " . $e->getMessage() . "\n";
        }
    }

    echo "Backfill complete: $made journal entries created, $skipped skipped.\n";
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
