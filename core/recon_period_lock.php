<?php
/**
 * core/recon_period_lock.php
 * --------------------------
 * Period-lock guard for bank reconciliation (Phase 4 — G8).
 *
 * Once a bank reconciliation is finalized (status = 'reconciled'), the period
 * is LOCKED: no journal entry whose date falls within that period and touches
 * one of the reconciled bank accounts may be voided, reversed, or edited.
 *
 * Call assertNotInFinalizedReconPeriod() from void_journal, reverse_journal,
 * and update_journal before making any changes.
 */

if (!function_exists('assertNotInFinalizedReconPeriod')) {
    /**
     * Throws an Exception if the journal entry's date falls within a finalized
     * reconciliation period for any bank/cash account it touches.
     *
     * @param PDO $pdo
     * @param int $entryId    journal_entries.entry_id
     * @throws Exception      descriptive message naming the locked period
     */
    function assertNotInFinalizedReconPeriod(PDO $pdo, int $entryId): void
    {
        // Load the entry date
        $entry = $pdo->prepare("SELECT entry_date FROM journal_entries WHERE entry_id = ?");
        $entry->execute([$entryId]);
        $row = $entry->fetch(PDO::FETCH_ASSOC);
        if (!$row) return; // entry not found — let the caller handle that

        $entryDate = $row['entry_date'];

        // Load account IDs from this entry's items
        $acctStmt = $pdo->prepare("SELECT DISTINCT account_id FROM journal_entry_items WHERE entry_id = ?");
        $acctStmt->execute([$entryId]);
        $acctIds = array_column($acctStmt->fetchAll(PDO::FETCH_ASSOC), 'account_id');

        if (empty($acctIds)) return;

        // Check if any of those accounts have a finalized reconciliation covering this date.
        // We join directly to bank_reconciliations on bank_account_id — only accounts
        // that are the subject of a reconciliation can ever be locked.
        $in  = implode(',', array_map('intval', $acctIds));
        $chk = $pdo->prepare("
            SELECT reconciliation_id, reconciliation_number, period_start, period_end
              FROM bank_reconciliations
             WHERE bank_account_id IN ($in)
               AND status = 'reconciled'
               AND period_start <= ?
               AND period_end   >= ?
             LIMIT 1
        ");
        $chk->execute([$entryDate, $entryDate]);
        $locked = $chk->fetch(PDO::FETCH_ASSOC);

        if ($locked) {
            throw new Exception(sprintf(
                'Cannot modify this entry: its date (%s) falls within a finalized bank reconciliation '
                . '(%s, period %s – %s). Unreconcile that period first.',
                $entryDate,
                $locked['reconciliation_number'],
                $locked['period_start'],
                $locked['period_end']
            ));
        }
    }
}
