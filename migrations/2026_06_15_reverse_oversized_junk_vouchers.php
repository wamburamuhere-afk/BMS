<?php
/**
 * 2026_06_15_reverse_oversized_junk_vouchers.php
 * -----------------------------------------------
 * Reverse the GL impact of payment vouchers whose amount is implausibly large —
 * a clear data-entry error (test/garbage). One such voucher ("PV-0003 — DUDE",
 * 1,200,000,000,000.00 → Dr 6-4000 Petty Cash Uncategorised / Cr 1-1150 Petty Cash)
 * single-handedly turned Operating Profit into −1.2 TRILLION and produced a
 * nonsensical "-176,467,440.3% of revenue" margin on the Income Statement.
 *
 * WHY THIS IS SAFE (criteria-based, never hard-coded ids/numbers)
 * The threshold is an objective SANITY CEILING far beyond any legitimate single
 * transaction for this business, yet far below the junk:
 *   • largest *legitimate* single posted journal line in the system: ~3.79 billion
 *   • next-largest payment voucher after the junk:                    ~4.35 million
 *   • the junk voucher:                                            1,200 billion
 * A ceiling of 100,000,000,000 (100 billion TZS) is ~26× the largest legit entry and
 * ~12× below the junk — it can only ever match genuine garbage. On a clean server it
 * matches nothing and the migration is a no-op.
 *
 * WHAT IT DOES (per matching voucher)
 *   1. Posts a BALANCED CONTRA of the voucher's GL entry (the exact inverse of every
 *      line) via postLedgerEntry — auditable, non-destructive (the original junk and
 *      its reversal both remain visible). Idempotent on
 *      (entity_type='oversized_voucher_reversal', entity_id = original entry_id).
 *   2. Marks the voucher 'cancelled' so the document state matches the reversed GL.
 *   3. assertLedgerBalanced() guardrail.
 *
 * SAFE TO RE-RUN — idempotent; re-running is a no-op.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/ledger_post.php';        // postLedgerEntry
require_once __DIR__ . '/../core/financial_reports.php';  // assertLedgerBalanced
global $pdo;

const JUNK_VOUCHER_CEILING = 100000000000.0;   // 100 billion TZS — see header rationale

echo "Starting migration: reverse oversized junk payment vouchers...\n";

try {
    $uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 0);
    if ($uid <= 0) {
        echo "  ! No users found — cannot resolve posting user. Aborting.\n";
        exit(1);
    }

    // ── Detect: posted GL mirror of a paid/approved voucher above the sanity ceiling ──
    $candidates = $pdo->query("
        SELECT
            je.entry_id,
            je.entry_date,
            je.project_id,
            pv.id            AS voucher_id,
            pv.voucher_number,
            pv.amount        AS voucher_amount
          FROM payment_vouchers pv
          JOIN journal_entries  je ON je.entity_type = 'books_transaction'
                                  AND je.entity_id   = pv.transaction_id
                                  AND je.status      = 'posted'
         WHERE pv.amount >= " . JUNK_VOUCHER_CEILING . "
           AND pv.status IN ('paid','approved')
         ORDER BY je.entry_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "  detected " . count($candidates) . " oversized junk voucher(s) (amount >= "
       . number_format(JUNK_VOUCHER_CEILING, 0) . ").\n";

    if (empty($candidates)) {
        echo "  ~ nothing to reverse (clean dataset).\n";
    }

    $reversed = 0;
    $skipped  = 0;

    foreach ($candidates as $c) {
        $entryId   = (int)$c['entry_id'];
        $voucherId = (int)$c['voucher_id'];
        $vnum      = $c['voucher_number'];
        $date      = substr((string)$c['entry_date'], 0, 10);
        $pid       = $c['project_id'] !== null ? (int)$c['project_id'] : null;

        // Idempotency — already reversed?
        $chk = $pdo->prepare("SELECT entry_id FROM journal_entries
                               WHERE entity_type='oversized_voucher_reversal' AND entity_id=? AND status='posted' LIMIT 1");
        $chk->execute([$entryId]);
        if ($chk->fetchColumn()) {
            // Ensure the voucher is also marked cancelled, then move on.
            $pdo->prepare("UPDATE payment_vouchers SET status='cancelled' WHERE id=? AND status IN ('paid','approved')")->execute([$voucherId]);
            $skipped++;
            continue;
        }

        // Read the original entry's lines and build the exact inverse.
        $lines = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id={$entryId}")->fetchAll(PDO::FETCH_ASSOC);
        if (!$lines) { echo "  ! entry {$entryId} has no lines — skipped.\n"; $skipped++; continue; }

        $contra = [];
        foreach ($lines as $ln) {
            $contra[] = [
                'account_id' => (int)$ln['account_id'],
                'type'       => ($ln['type'] === 'debit') ? 'credit' : 'debit',   // flip
                'amount'     => (float)$ln['amount'],
                'description'=> "Reversal of oversized junk voucher {$vnum}",
            ];
        }

        postLedgerEntry(
            $pdo,
            "Reverse oversized junk voucher {$vnum} (data-entry error)",
            $contra,
            $pid,
            $entryId,                          // entity_id = the entry being reversed
            'oversized_voucher_reversal',      // idempotency key type
            $date,
            $uid
        );

        $pdo->prepare("UPDATE payment_vouchers SET status='cancelled' WHERE id=?")->execute([$voucherId]);

        echo "  + reversed: voucher {$vnum} (entry {$entryId}, "
           . number_format((float)$c['voucher_amount'], 2) . ") → voucher cancelled.\n";
        $reversed++;
    }

    echo "  result: {$reversed} reversed, {$skipped} already-done/skipped.\n";

    // ── Balance guardrail ─────────────────────────────────────────────────────
    $g = assertLedgerBalanced($pdo);
    $ledgerOk = $g['ledger_balanced'] ?? false;
    echo "  guardrail: ledger_balanced=" . ($ledgerOk ? 'true' : 'false')
       . " bs_balanced=" . (($g['bs_balanced'] ?? false) ? 'true' : 'false') . "\n";
    if (!$ledgerOk) {
        echo "  ! LEDGER OUT OF BALANCE after migration — investigate before deploying.\n";
        exit(1);
    }

    echo "\nMigration complete.\n";

} catch (LedgerException $e) {
    echo "  ! Ledger validation error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
