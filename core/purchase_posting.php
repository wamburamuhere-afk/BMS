<?php
/**
 * core/purchase_posting.php
 * -------------------------
 * money.md OUT-7: post a GRN (goods received) to the canonical ledger
 * (journal_entries) as a balanced double-entry. GRN approval previously only
 * moved stock + tried the disabled `autoPostEvent('grn_approved')` mapping gate
 * (is_active=0) — so Inventory never reached the GL (1-1300 read 0).
 *
 * Double-entry (goods received on credit, no cash moves yet):
 *     Dr Inventory (asset arrives)  /  Cr Accounts Payable (owed to supplier)
 * for the value of goods received = Σ(receipt_items.quantity_received × unit_price).
 * Input VAT is NOT recognised here — it belongs with the supplier TAX invoice,
 * not the goods-receipt note. The supplier PAYMENT (OUT-3) later clears the AP.
 *
 * Design rules (match core/sales_posting.php):
 *   - Best-effort: NEVER throws — goods physically arrived, so the receipt must
 *     record even if accounting can't post; the caller logs a ledger warning.
 *   - Idempotent on (entity_type='grn', entity_id=receipt_id): re-approving never
 *     double-posts.
 *   - Joins the caller's open transaction; never touches accounts.current_balance.
 *   - AP is credited via apAccountId() — the SAME control account the supplier
 *     payment debits — so the payable nets to zero across receive→pay.
 */

require_once __DIR__ . '/ledger_post.php';   // postLedgerEntry
require_once __DIR__ . '/gl_accounts.php';   // inventoryAccountId, apAccountId

if (!function_exists('_pp_already_posted')) {
    function _pp_already_posted(PDO $pdo, string $entityType, int $entityId): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type=? AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$entityType, $entityId]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('grnReceiptValue')) {
    /** Value of goods received on a GRN = Σ(quantity_received × unit_price). */
    function grnReceiptValue(PDO $pdo, int $receiptId): float
    {
        $v = $pdo->query("SELECT COALESCE(SUM(quantity_received * unit_price), 0)
                            FROM receipt_items WHERE receipt_id = " . (int)$receiptId)->fetchColumn();
        return round((float)$v, 2);
    }
}

if (!function_exists('postGrnReceipt')) {
    /**
     * OUT-7 — post a GRN's inventory receipt. Never throws.
     *
     * @param float  $grnTotal  Goods value (Σ qty×unit_price). If <= 0 it is
     *                          recomputed from receipt_items as a safety net.
     * @return array ['posted'=>bool, 'reason'=>string, 'entry_id'?=>int]
     */
    function postGrnReceipt(
        PDO $pdo, int $receiptId, float $grnTotal, string $date,
        ?int $projectId, int $userId, ?string $reference
    ): array {
        $out = ['posted' => false, 'reason' => ''];
        if ($receiptId <= 0) { $out['reason'] = 'no_receipt'; return $out; }

        if ($grnTotal <= 0) $grnTotal = grnReceiptValue($pdo, $receiptId);
        $grnTotal = round($grnTotal, 2);
        if ($grnTotal <= 0) { $out['reason'] = 'no_amount'; return $out; }

        // Idempotency — already in the ledger?
        if ($existing = _pp_already_posted($pdo, 'grn', $receiptId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;

        $inv = inventoryAccountId($pdo);
        $ap  = apAccountId($pdo);
        if (!$inv || !$ap) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $desc = 'GRN ' . ($reference ?: ('#' . $receiptId)) . ' — goods received';
        try {
            $entry = postLedgerEntry($pdo, $desc, [
                ['account_id' => (int)$inv, 'type' => 'debit',  'amount' => $grnTotal, 'description' => 'Goods received into inventory'],
                ['account_id' => (int)$ap,  'type' => 'credit', 'amount' => $grnTotal, 'description' => 'Owed to supplier (Accounts Payable)'],
            ], $pid, $receiptId, 'grn', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postGrnReceipt failed (receipt $receiptId): " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}
