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

require_once __DIR__ . '/ledger_post.php';     // postLedgerEntry
require_once __DIR__ . '/gl_accounts.php';     // inventoryAccountId, apAccountId, cogsAccountId
require_once __DIR__ . '/expense_posting.php'; // reverseAccrualEntry (shared reversal)

if (!function_exists('_pp_already_posted')) {
    function _pp_already_posted(PDO $pdo, string $entityType, int $entityId): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type=? AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$entityType, $entityId]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('ppAccrualVatLines')) {
    /**
     * Build the balanced debit/credit lines for a Bill accrual, splitting out
     * recoverable Input VAT so it is NOT buried in the cost account.
     *
     *   Dr Cost (net = toPost − tax)        ← the true cost, VAT-exclusive
     *   Dr Input VAT Recoverable (tax)      ← an ASSET (reclaimable from TRA)   [only when VAT applies]
     *      Cr Accounts Payable (toPost)     ← gross owed to the supplier
     *
     * $toPost = the gross amount this entry recognises (already net of any GRN
     * cutover). $tax = the invoice's VAT (tax_amount); 0 when not a VAT bill.
     *
     * Falls back to the original 2-line gross entry (Dr Cost / Cr AP) when there
     * is no VAT, the Input VAT account can't be resolved, or the net cost would
     * be ≤ 0 (a rare GRN-cutover edge) — so the entry always balances and never
     * posts a negative or zero leg.
     *
     * @return array[] postLedgerEntry-shaped lines (always balanced: ΣDr = ΣCr = toPost).
     */
    function ppAccrualVatLines(PDO $pdo, int $costAcc, string $costDesc, int $apAcc, float $toPost, float $tax): array
    {
        $toPost = round($toPost, 2);
        $tax    = round(max(0.0, $tax), 2);
        $vatAcc = $tax > 0 ? inputVatAccountId($pdo) : null;
        $costDebit = round($toPost - $tax, 2);

        if ($tax > 0 && $vatAcc && $costDebit > 0) {
            return [
                ['account_id' => $costAcc, 'type' => 'debit',  'amount' => $costDebit, 'description' => $costDesc . ' (net of VAT)'],
                ['account_id' => (int)$vatAcc, 'type' => 'debit', 'amount' => $tax,    'description' => 'Input VAT recoverable'],
                ['account_id' => $apAcc,   'type' => 'credit', 'amount' => $toPost,    'description' => 'Owed to supplier (Accounts Payable)'],
            ];
        }
        // No VAT (or unresolved / would-be-negative net) → original 2-line gross entry.
        return [
            ['account_id' => $costAcc, 'type' => 'debit',  'amount' => $toPost, 'description' => $costDesc],
            ['account_id' => $apAcc,   'type' => 'credit', 'amount' => $toPost, 'description' => 'Owed to supplier (Accounts Payable)'],
        ];
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

if (!function_exists('postSubcontractorAccrual')) {
    /**
     * money.md OUT-3 — recognise a SUB-CONTRACTOR supplier invoice as COGS when it
     * is approved: Dr Cost of Goods Sold / Cr Accounts Payable (net of VAT; input
     * VAT is handled separately by postInputVat). This is a construction COGS the
     * Income Statement reads from supplier_invoices, so it must reach the GL accrually.
     * The supplier payment later settles the same AP. Best-effort, idempotent on
     * (entity_type='subcontractor_invoice', id). Only sub_contractor invoices accrue
     * here — goods supplier invoices raise AP via GRN, so they are skipped to avoid
     * double-counting.
     *
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function postSubcontractorAccrual(PDO $pdo, int $invoiceId, int $userId): array
    {
        $out = ['posted' => false, 'reason' => ''];
        if ($invoiceId <= 0) { $out['reason'] = 'invalid'; return $out; }

        $r = $pdo->prepare("SELECT amount, tax_amount, invoice_type, project_id, date_raised FROM supplier_invoices WHERE id = ?");
        $r->execute([$invoiceId]);
        $inv = $r->fetch(PDO::FETCH_ASSOC);
        if (!$inv) { $out['reason'] = 'not_found'; return $out; }
        if (($inv['invoice_type'] ?? '') !== 'sub_contractor') { $out['reason'] = 'not_subcontractor'; return $out; }

        $amount = round((float)$inv['amount'], 2);
        if ($amount <= 0) { $out['reason'] = 'no_amount'; return $out; }

        if ($existing = _pp_already_posted($pdo, 'subcontractor_invoice', $invoiceId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $cogs = cogsAccountId($pdo);
        $ap   = apAccountId($pdo);
        if (!$cogs || !$ap) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$inv['date_raised']) ? substr((string)$inv['date_raised'], 0, 10) : date('Y-m-d');
        $pid  = !empty($inv['project_id']) ? (int)$inv['project_id'] : null;
        // Split recoverable Input VAT out of COGS (was buried in the gross amount).
        $tax = round((float)($inv['tax_amount'] ?? 0), 2);
        $lines = ppAccrualVatLines($pdo, (int)$cogs, 'Sub-contractor cost (COGS)', (int)$ap, $amount, $tax);
        try {
            $entry = postLedgerEntry($pdo, "Sub-contractor invoice #$invoiceId — cost certified", $lines, $pid, $invoiceId, 'subcontractor_invoice', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postSubcontractorAccrual failed (invoice $invoiceId): " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reverseSubcontractorAccrual')) {
    /** Reverse a sub-contractor COGS accrual (invoice deleted / pushed back). */
    function reverseSubcontractorAccrual(PDO $pdo, int $invoiceId, int $userId): array
    {
        return reverseAccrualEntry($pdo, 'subcontractor_invoice', $invoiceId, $userId);
    }
}

if (!function_exists('postGoodsInvoiceAccrual')) {
    /**
     * money.md OUT-7 policy change — recognise a GOODS supplier invoice's payable
     * at INVOICE-APPROVAL time instead of GRN time: Dr Inventory / Cr Accounts
     * Payable for the invoice's own amount. GRN approval no longer posts to the
     * GL (api/approve_grn.php); it still only moves physical stock.
     *
     * Cutover guard (amount-based, not a yes/no flag): nets off whatever value
     * this invoice's PO already posted via GRN under the OLD rule (Σ AP credits
     * on posted entity_type='grn' entries for receipts under the same PO), and
     * posts only the shortfall. This avoids double-counting a PO whose GRN
     * already posted, AND self-heals a PO whose GRNs only partially posted
     * (e.g. a legacy receipt approved before GRN posting existed at all).
     *
     * Idempotent on (entity_type='supplier_invoice', id). Best-effort: never
     * throws — invoice approval must always succeed even if posting fails.
     *
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int,'covered_by_grn'?=>float]
     */
    function postGoodsInvoiceAccrual(PDO $pdo, int $invoiceId, int $userId): array
    {
        $out = ['posted' => false, 'reason' => ''];
        if ($invoiceId <= 0) { $out['reason'] = 'invalid'; return $out; }

        $r = $pdo->prepare("SELECT amount, tax_amount, invoice_type, po_id, project_id, date_raised, cost_account_id FROM supplier_invoices WHERE id = ?");
        $r->execute([$invoiceId]);
        $inv = $r->fetch(PDO::FETCH_ASSOC);
        if (!$inv) { $out['reason'] = 'not_found'; return $out; }
        if (($inv['invoice_type'] ?? '') !== 'supplier') { $out['reason'] = 'not_goods_invoice'; return $out; }

        $amount = round((float)$inv['amount'], 2);
        if ($amount <= 0) { $out['reason'] = 'no_amount'; return $out; }

        if ($existing = _pp_already_posted($pdo, 'supplier_invoice', $invoiceId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $ap = apAccountId($pdo);

        // Amount-based cutover guard.
        $covered = 0.0;
        if (!empty($inv['po_id']) && $ap) {
            $cv = $pdo->prepare("
                SELECT COALESCE(SUM(jei.amount), 0)
                  FROM journal_entries je
                  JOIN journal_entry_items jei ON jei.entry_id = je.entry_id AND jei.type = 'credit' AND jei.account_id = ?
                  JOIN purchase_receipts pr ON pr.receipt_id = je.entity_id
                 WHERE je.entity_type = 'grn' AND je.status = 'posted'
                   AND pr.purchase_order_id = ?
            ");
            $cv->execute([(int)$ap, (int)$inv['po_id']]);
            $covered = round((float)$cv->fetchColumn(), 2);
        }

        $toPost = round($amount - $covered, 2);
        if ($toPost <= 0) {
            $out['posted'] = true; $out['reason'] = 'covered_by_grn'; $out['covered_by_grn'] = $covered;
            logActivity($pdo, $userId, "Invoice #$invoiceId: payable already recorded via GRN posting(s) for PO #{$inv['po_id']} ("
                . number_format($covered, 2) . ") — no new entry posted.");
            return $out;
        }

        // Cost account: the Bill may choose WHERE the cost lands (an Expense, COGS,
        // or Asset/Inventory leaf account). Falls back to the canonical Inventory
        // account when the Bill didn't pick one — zero regression for existing /
        // legacy Bills. The chosen account must exist and be active.
        $debitAcc = null;
        if (!empty($inv['cost_account_id'])) {
            $chk = $pdo->prepare("SELECT account_id FROM accounts WHERE account_id = ? AND status = 'active'");
            $chk->execute([(int)$inv['cost_account_id']]);
            $debitAcc = (int)($chk->fetchColumn() ?: 0) ?: null;
        }
        $usedChosen = ($debitAcc !== null);
        if (!$debitAcc) $debitAcc = inventoryAccountId($pdo);
        if (!$debitAcc || !$ap) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$inv['date_raised']) ? substr((string)$inv['date_raised'], 0, 10) : date('Y-m-d');
        $pid  = !empty($inv['project_id']) ? (int)$inv['project_id'] : null;
        $desc = $covered > 0
            ? "Supplier invoice #$invoiceId — remaining payable not covered by an earlier GRN posting"
            : "Supplier invoice #$invoiceId — payable recognised";
        $debitDesc = $usedChosen ? 'Cost recognised (selected account)' : 'Goods received into inventory';
        // Split recoverable Input VAT out of the cost (the full invoice VAT, since a
        // GRN never posts VAT). When covered>0, the VAT still applies to the whole
        // invoice, so it comes off the remaining cost: Dr Cost = toPost − tax.
        $tax = round((float)($inv['tax_amount'] ?? 0), 2);
        $lines = ppAccrualVatLines($pdo, (int)$debitAcc, $debitDesc, (int)$ap, $toPost, $tax);
        try {
            $entry = postLedgerEntry($pdo, $desc, $lines, $pid, $invoiceId, 'supplier_invoice', $date, $userId);
            $out['posted'] = true; $out['entry_id'] = $entry;
            $out['reason'] = $covered > 0 ? 'posted_partial_remainder' : 'posted';
            if ($covered > 0) {
                logActivity($pdo, $userId, "Invoice #$invoiceId: posted remaining payable " . number_format($toPost, 2)
                    . " (" . number_format($covered, 2) . " already covered by GRN posting(s) for PO #{$inv['po_id']}).");
            }
        } catch (Throwable $e) {
            error_log("postGoodsInvoiceAccrual failed (invoice $invoiceId): " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reverseGoodsInvoiceAccrual')) {
    /** Reverse a goods-invoice payable accrual (invoice deleted / pushed back). */
    function reverseGoodsInvoiceAccrual(PDO $pdo, int $invoiceId, int $userId): array
    {
        return reverseAccrualEntry($pdo, 'supplier_invoice', $invoiceId, $userId);
    }
}

if (!function_exists('remediateBuriedVatForEntry')) {
    /**
     * Phase 2 remediation: an OLD Bill accrual posted the cost GROSS (VAT buried)
     * with no Input VAT line. Move the VAT out without disturbing AP, by posting a
     * balanced reclassification:
     *
     *     Dr Input VAT Recoverable (tax)   /   Cr <the original cost account> (tax)
     *
     * Net effect: cost drops to net, Input VAT appears, AP unchanged. The reclass
     * is itself balanced (Dr = Cr = tax) so the ledger stays balanced.
     *
     * Safe + idempotent. Skips when: not a Bill accrual, no VAT, the entry already
     * has an Input VAT line (already split by the new code), a remediation already
     * exists for this bill, the entry isn't a clean single-cost-debit gross entry,
     * or the cost debit is smaller than the VAT (ambiguous — left for manual review).
     *
     * @return array ['done'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function remediateBuriedVatForEntry(PDO $pdo, int $entryId, int $userId): array
    {
        $out = ['done' => false, 'reason' => ''];
        $e = $pdo->prepare("SELECT entry_id, entity_type, entity_id, entry_date, project_id FROM journal_entries WHERE entry_id = ? AND status = 'posted'");
        $e->execute([$entryId]);
        $row = $e->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $out['reason'] = 'not_found'; return $out; }
        if (!in_array($row['entity_type'], ['supplier_invoice', 'subcontractor_invoice'], true)) { $out['reason'] = 'not_bill_accrual'; return $out; }

        $billId = (int)$row['entity_id'];
        $tax = round((float)$pdo->query("SELECT COALESCE(tax_amount, 0) FROM supplier_invoices WHERE id = $billId")->fetchColumn(), 2);
        if ($tax <= 0) { $out['reason'] = 'no_vat'; return $out; }

        $vatAcc = inputVatAccountId($pdo);
        if (!$vatAcc) { $out['reason'] = 'no_vat_account'; return $out; }

        // Already split by the new posting code?
        if ((int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE entry_id = $entryId AND account_id = " . (int)$vatAcc)->fetchColumn() > 0) {
            $out['reason'] = 'already_split'; return $out;
        }
        // Already remediated for this bill?
        $rem = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type = 'bill_vat_remediation' AND entity_id = ? AND status = 'posted' LIMIT 1");
        $rem->execute([$billId]);
        if ($rem->fetchColumn()) { $out['done'] = true; $out['reason'] = 'already_remediated'; return $out; }

        // Must be a clean single cost-debit gross entry.
        $debits = $pdo->query("SELECT account_id, amount FROM journal_entry_items WHERE entry_id = $entryId AND type = 'debit'")->fetchAll(PDO::FETCH_ASSOC);
        if (count($debits) !== 1) { $out['reason'] = 'ambiguous_debits'; return $out; }
        $costAcc = (int)$debits[0]['account_id'];
        $costAmt = round((float)$debits[0]['amount'], 2);
        if ($costAcc === (int)$vatAcc) { $out['reason'] = 'cost_is_vat'; return $out; }
        if ($costAmt < $tax)           { $out['reason'] = 'cost_lt_tax'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$row['entry_date']) ? substr((string)$row['entry_date'], 0, 10) : date('Y-m-d');
        $pid  = !empty($row['project_id']) ? (int)$row['project_id'] : null;
        try {
            $newId = postLedgerEntry($pdo, "VAT reclass — move Input VAT out of cost (bill #$billId)", [
                ['account_id' => (int)$vatAcc, 'type' => 'debit',  'amount' => $tax, 'description' => 'Input VAT recoverable (reclassified out of cost)'],
                ['account_id' => $costAcc,     'type' => 'credit', 'amount' => $tax, 'description' => 'Remove VAT previously buried in cost'],
            ], $pid, $billId, 'bill_vat_remediation', $date, $userId);
            $pdo->prepare("UPDATE journal_entries SET parent_entity_type = 'supplier_invoice', parent_entity_id = ? WHERE entry_id = ?")
                ->execute([$billId, $newId]);
            $out['done'] = true; $out['reason'] = 'remediated'; $out['entry_id'] = $newId;
        } catch (Throwable $ex) {
            error_log("remediateBuriedVatForEntry failed (entry $entryId): " . $ex->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('supplierInvoiceHasPayments')) {
    /**
     * True if a Bill (supplier_invoice) has any recorded payment. Used to block
     * deletion: deleting reverses the AP accrual, but the payment's own entry
     * (Dr AP / Cr Bank) would remain → AP corrupted. Detects payments across all
     * paths — the partial-payment subledger, the legacy single-payment link,
     * a non-zero amount_paid, and the partial/paid statuses.
     */
    function supplierInvoiceHasPayments(PDO $pdo, int $invoiceId): bool
    {
        if ($invoiceId <= 0) return false;
        $r = $pdo->prepare("SELECT amount_paid, status, payment_transaction_id FROM supplier_invoices WHERE id = ?");
        $r->execute([$invoiceId]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        if ((float)($row['amount_paid'] ?? 0) > 0.01)               return true;
        if (!empty($row['payment_transaction_id']))                  return true;
        if (in_array($row['status'], ['partial', 'paid'], true))     return true;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_invoice_payments WHERE invoice_id = ?");
        $stmt->execute([$invoiceId]);
        return ((int)$stmt->fetchColumn()) > 0;
    }
}

if (!function_exists('purchaseReturnValue')) {
    /** Goods value of a purchase return = Σ(quantity × unit_price), net of tax. */
    function purchaseReturnValue(PDO $pdo, int $returnId): float
    {
        $v = $pdo->query("SELECT COALESCE(SUM(quantity * unit_price), 0)
                            FROM purchase_return_items WHERE purchase_return_id = " . (int)$returnId)->fetchColumn();
        return round((float)$v, 2);
    }
}

if (!function_exists('postPurchaseReturn')) {
    /**
     * money.md OUT-8 — post a purchase return (goods sent back to the supplier) to the
     * canonical ledger when it is APPROVED. It is the exact contra of the GRN (OUT-7):
     *
     *     Dr Accounts Payable (we owe the supplier less)  /  Cr Inventory (goods leave)
     *
     * for the goods value (net of tax) = purchase_returns.total_amount. Input VAT is
     * deliberately NOT reversed here, mirroring postGrnReceipt() which does not book
     * input VAT on goods receipt (VAT lives with the supplier TAX invoice / payment) —
     * so the receive→return pair nets cleanly to zero on Inventory and AP. AP is the
     * same control account the GRN credits and the supplier payment debits.
     *
     * Best-effort (never throws — the goods physically left, so the return must record
     * even if accounting can't post); idempotent on (entity_type='purchase_return', id);
     * joins the caller's open transaction; never touches accounts.current_balance.
     *
     * @return array ['posted'=>bool, 'reason'=>string, 'entry_id'?=>int]
     */
    function postPurchaseReturn(PDO $pdo, int $returnId, int $userId): array
    {
        $out = ['posted' => false, 'reason' => ''];
        if ($returnId <= 0) { $out['reason'] = 'invalid'; return $out; }

        $r = $pdo->prepare("SELECT return_number, return_date, total_amount FROM purchase_returns WHERE purchase_return_id = ?");
        $r->execute([$returnId]);
        $ret = $r->fetch(PDO::FETCH_ASSOC);
        if (!$ret) { $out['reason'] = 'not_found'; return $out; }

        $value = round((float)($ret['total_amount'] ?? 0), 2);
        if ($value <= 0) $value = purchaseReturnValue($pdo, $returnId);
        if ($value <= 0) { $out['reason'] = 'no_amount'; return $out; }

        if ($existing = _pp_already_posted($pdo, 'purchase_return', $returnId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $inv = inventoryAccountId($pdo);
        $ap  = apAccountId($pdo);
        if (!$inv || !$ap) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$ret['return_date']) ? substr((string)$ret['return_date'], 0, 10) : date('Y-m-d');
        $ref  = $ret['return_number'] ?: ('#' . $returnId);
        try {
            $entry = postLedgerEntry($pdo, "Purchase return $ref — goods returned to supplier", [
                ['account_id' => (int)$ap,  'type' => 'debit',  'amount' => $value, 'description' => 'Supplier debt reduced (Accounts Payable)'],
                ['account_id' => (int)$inv, 'type' => 'credit', 'amount' => $value, 'description' => 'Goods returned out of inventory'],
            ], null, $returnId, 'purchase_return', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postPurchaseReturn failed (return $returnId): " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reversePurchaseReturn')) {
    /** Reverse a purchase-return posting (return later rejected / cancelled). */
    function reversePurchaseReturn(PDO $pdo, int $returnId, int $userId): array
    {
        return reverseAccrualEntry($pdo, 'purchase_return', $returnId, $userId);
    }
}
