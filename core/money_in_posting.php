<?php
/**
 * core/money_in_posting.php
 * -------------------------
 * B1 (money_plan.md): post MONEY-IN events (customer invoice payments, multi-invoice
 * receipts, recognised revenue) as ONE balanced double-entry into the canonical ledger
 * (journal_entries) via postLedgerEntry — so the cash/bank account is debited and the
 * matching credit (Accounts Receivable, or Income) is recorded in the SAME ledger the
 * Balance Sheet reads.
 *
 *   Dr  <Received-Into bank/cash>  (net of any WHT)
 *   Dr  WHT Receivable             (withheld amount, if any)          ← existing sales-side WHT
 *     Cr <credit account>          (gross: AR for payments, Income for revenue)
 *
 * Idempotent on (entity_type, entity_id) — re-running never double-posts, so the two
 * payment paths (record_payment.php + save_receipt.php) are safe together.
 *
 * Joins the caller's open transaction; never touches accounts.current_balance (the GL is
 * the single source of truth). Reversal = a contra entry, handled by the caller on void.
 */

require_once __DIR__ . '/ledger_post.php';   // postLedgerEntry
require_once __DIR__ . '/gl_accounts.php';   // arAccountId, bankAccountResolve, gl_account_active

if (!function_exists('postDepositEntry')) {
    /**
     * Generic money-in posting: Dr bank (net) [+ Dr WHT] / Cr <creditAccountId> (gross).
     *
     * @param string  $entityType   'payment' | 'revenue' (idempotency key)
     * @param int     $entityId     the source-document id
     * @param int     $bankAccountId chosen Received-Into cash/bank account
     * @param int     $creditAccountId AR (payments) or Income (revenue)
     * @param float   $amount       gross amount received (what the customer paid / revenue value)
     * @param float   $whtAmount    sales-side WHT withheld by the customer (0 if none)
     * @param ?int    $whtAccountId WHT Receivable account (required when $whtAmount > 0)
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?:int,'existing_entry_id'?:int]
     */
    function postDepositEntry(
        PDO $pdo, string $entityType, int $entityId,
        int $bankAccountId, int $creditAccountId, float $amount,
        string $date, ?string $reference, string $description,
        ?int $projectId, int $userId,
        float $whtAmount = 0.0, ?int $whtAccountId = null
    ): array {
        if ($entityId <= 0)               return ['posted' => false, 'reason' => 'invalid_entity'];
        $amount = round($amount, 2);
        if ($amount <= 0)                 return ['posted' => false, 'reason' => 'no_amount'];

        // Idempotency — already posted for this document?
        $chk = $pdo->prepare("SELECT entry_id FROM journal_entries
                               WHERE entity_type = ? AND entity_id = ? AND status = 'posted' LIMIT 1");
        $chk->execute([$entityType, $entityId]);
        if ($existing = $chk->fetchColumn()) {
            return ['posted' => false, 'reason' => 'already_posted', 'existing_entry_id' => (int)$existing];
        }

        // Received-Into must be an active account (the picker already constrains it to
        // cash/bank; we accept any active account so a legit deposit is never blocked).
        if (!gl_account_active($pdo, $bankAccountId))  return ['posted' => false, 'reason' => 'bank_account_invalid'];
        if (!gl_account_active($pdo, $creditAccountId)) return ['posted' => false, 'reason' => 'credit_account_not_configured'];

        $wht = ($whtAmount > 0 && $whtAccountId && gl_account_active($pdo, $whtAccountId)) ? round($whtAmount, 2) : 0.0;
        $net = round($amount - $wht, 2);
        if ($wht > 0 && $net <= 0)         return ['posted' => false, 'reason' => 'wht_exceeds_amount'];

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;

        $lines = [['account_id' => $bankAccountId, 'type' => 'debit', 'amount' => $net, 'description' => $description]];
        if ($wht > 0) {
            $lines[] = ['account_id' => (int)$whtAccountId, 'type' => 'debit', 'amount' => $wht, 'description' => 'WHT receivable (withheld by customer)'];
        }
        $lines[] = ['account_id' => $creditAccountId, 'type' => 'credit', 'amount' => $amount, 'description' => $description];

        $entryId = postLedgerEntry($pdo, $description, $lines, $pid, $entityId, $entityType, $date, $userId);
        return ['posted' => true, 'reason' => 'posted', 'entry_id' => (int)$entryId];
    }
}

if (!function_exists('postPaymentReceived')) {
    /**
     * IN-1 / IN-2 — a customer payment that clears Accounts Receivable.
     *   Dr Received-Into (net) [+ Dr WHT Receivable] / Cr Accounts Receivable (gross)
     * Resolves AR via gl_accounts (no dependence on the empty journal_mappings row).
     */
    function postPaymentReceived(
        PDO $pdo, int $paymentId, int $receivedIntoAccountId, float $amount,
        string $date, ?string $reference, string $description, ?int $projectId, int $userId,
        float $whtAmount = 0.0, ?int $whtAccountId = null
    ): array {
        $ar = arAccountId($pdo);
        if (!$ar) return ['posted' => false, 'reason' => 'credit_account_not_configured'];
        return postDepositEntry($pdo, 'payment', $paymentId, $receivedIntoAccountId, (int)$ar,
            $amount, $date, $reference, $description, $projectId, $userId, $whtAmount, $whtAccountId);
    }
}

