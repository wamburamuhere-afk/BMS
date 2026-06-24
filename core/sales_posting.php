<?php
/**
 * core/sales_posting.php
 * ----------------------
 * B2 (money_plan.md): post POS sales (IN-5) and POS returns (IN-6) to the canonical
 * ledger (journal_entries) as balanced double-entries. POS previously wrote pos_sales
 * only — zero accounting.
 *
 * A sale posts TWO entries (two distinct economic events):
 *   1. Revenue   Dr Cash/Bank (amount paid) + Dr Accounts Receivable (balance due)
 *                  Cr Sales Revenue (net of VAT)
 *                  Cr Output VAT Payable (tax)
 *   2. COGS      Dr Cost of Goods Sold  /  Cr Inventory   (Σ qty × products.cost_price)
 *
 * A return posts the contra of each (for the returned portion).
 *
 * Design rules:
 *   - Best-effort: NEVER throws — a missing account or config issue returns a reason and
 *     leaves the sale intact (a cash sale must never fail because of accounting).
 *   - Idempotent on (entity_type, entity_id): 'pos_sale'/'pos_cogs' for sales,
 *     'pos_return'/'pos_return_cogs' for returns.
 *   - Joins the caller's open transaction; never touches accounts.current_balance.
 */

require_once __DIR__ . '/ledger_post.php';   // postLedgerEntry, LedgerException
require_once __DIR__ . '/gl_accounts.php';   // arAccountId, inventoryAccountId, cogsAccountId, salesRevenueAccountId, salesReturnsAccountId
require_once __DIR__ . '/vat.php';           // outputVatAccountId

if (!function_exists('posReceiptAccountId')) {
    /**
     * The cash/bank GL account a POS tender lands in, by payment method.
     *
     * Resolution (Option B — admin-configurable, not hardcoded):
     *   ① system_settings  pos_<method>_account_id   (admin maps each tender → account)
     *   ② sensible code default per method            (cash→1-1130, mobile→1-1190, card/bank→1-1110)
     *   ③ first active cash/bank leaf                 (last resort, never null when one exists)
     *
     * Method is metadata; this only picks which REAL account the money sits in. POS has a
     * single method field (no separate account picker) so method↔account can't mismatch.
     */
    function posReceiptAccountId(PDO $pdo, string $method): ?int
    {
        // ① configurable setting per method
        $key = 'pos_' . preg_replace('/[^a-z_]/', '', strtolower($method)) . '_account_id';
        $v = gl_setting_account($pdo, $key);                         // active id, or 0
        if ($v) return $v;

        // ② code default per method
        $code = match ($method) {
            'mobile_money'                 => '1-1190',
            'card', 'bank_transfer'        => '1-1110',
            default                        => '1-1130',   // cash, voucher, loyalty_points, split, other
        };
        $v = gl_account_by_code($pdo, $code);
        if ($v && gl_account_active($pdo, $v)) return $v;

        // ③ first active cash/bank leaf
        $v = (int)($pdo->query("SELECT a.account_id FROM accounts a
                                  LEFT JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
                                 WHERE a.status='active' AND a.account_type='asset'
                                   AND (st.is_bank=1 OR a.cash_flow_category='cash')
                                   AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id=a.account_id)
                                 ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
        return $v ?: null;
    }
}

if (!function_exists('posSaleCogs')) {
    /**
     * Σ(quantity × products.cost_price) for a POS sale — the Income-Statement convention.
     *
     * Guards against corrupt cost data: a product whose cost_price exceeds its
     * selling_price (with selling_price > 0) is a clear data-entry error — you do
     * not stock goods at many times their sale price. Including such a line would
     * inject a bogus COGS into the ledger, so it contributes 0 here (and the backfill
     * reports it). Once the cost_price is corrected the line is counted normally, so
     * a re-run is self-healing. Criteria-based — no product ids hard-coded.
     */
    function posSaleCogs(PDO $pdo, int $saleId): float
    {
        $v = $pdo->query("SELECT COALESCE(SUM(si.quantity * COALESCE(p.cost_price,0)),0)
                            FROM pos_sale_items si
                            JOIN products p ON si.product_id = p.product_id
                           WHERE si.sale_id = " . (int)$saleId . "
                             AND NOT (p.cost_price > p.selling_price AND p.selling_price > 0)")->fetchColumn();
        return round((float)$v, 2);
    }
}

if (!function_exists('_sp_already_posted')) {
    function _sp_already_posted(PDO $pdo, string $entityType, int $entityId): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type=? AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$entityType, $entityId]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('postPosSale')) {
    /**
     * IN-5 — post a completed POS sale's revenue + COGS. Never throws.
     *
     * @return array ['revenue'=>bool,'cogs'=>bool,'reason'=>string]
     */
    function postPosSale(
        PDO $pdo, int $saleId, string $method, float $cashPaid, float $balanceDue,
        float $grandTotal, float $tax, string $date, ?string $reference,
        ?int $projectId, int $userId
    ): array {
        $out = ['revenue' => false, 'cogs' => false, 'reason' => ''];
        if ($saleId <= 0 || $grandTotal <= 0) { $out['reason'] = 'no_sale'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;
        $tax  = round($tax, 2); if ($tax < 0 || $tax > $grandTotal) $tax = 0.0;
        $cashPaid = round(max(0.0, $cashPaid), 2);
        $balanceDue = round(max(0.0, $balanceDue), 2);
        // reconcile splits to the gross (defensive)
        if (abs(($cashPaid + $balanceDue) - $grandTotal) > 0.01) {
            $balanceDue = round($grandTotal - $cashPaid, 2);
            if ($balanceDue < 0) { $cashPaid = $grandTotal; $balanceDue = 0.0; }
        }
        $netRevenue = round($grandTotal - $tax, 2);
        $desc = "POS sale " . ($reference ?: ('#' . $saleId));

        // ── Entry 1: Revenue ───────────────────────────────────────────────
        if (!_sp_already_posted($pdo, 'pos_sale', $saleId)) {
            $cash = ($cashPaid > 0) ? posReceiptAccountId($pdo, $method) : null;
            $ar   = ($balanceDue > 0) ? arAccountId($pdo) : null;
            $rev  = salesRevenueAccountId($pdo);
            $vat  = ($tax > 0) ? outputVatAccountId($pdo) : null;

            if (!$rev || ($cashPaid > 0 && !$cash) || ($balanceDue > 0 && !$ar)) {
                $out['reason'] = 'revenue_accounts_not_configured';
            } else {
                // Debit side: cash received + AR balance (always sums to grand_total).
                $lines = [];
                if ($cashPaid > 0)   $lines[] = ['account_id' => (int)$cash, 'type' => 'debit', 'amount' => $cashPaid,   'description' => $desc];
                if ($balanceDue > 0) $lines[] = ['account_id' => (int)$ar,   'type' => 'debit', 'amount' => $balanceDue, 'description' => $desc . ' (on credit)'];
                // Credit side: split revenue + VAT when a VAT account exists, else the whole
                // gross is revenue (so the entry always balances to grand_total).
                if ($vat && $tax > 0) {
                    $lines[] = ['account_id' => (int)$rev, 'type' => 'credit', 'amount' => $netRevenue, 'description' => 'POS sales revenue'];
                    $lines[] = ['account_id' => (int)$vat, 'type' => 'credit', 'amount' => $tax,        'description' => 'Output VAT'];
                } else {
                    $lines[] = ['account_id' => (int)$rev, 'type' => 'credit', 'amount' => $grandTotal, 'description' => 'POS sales revenue'];
                }
                try {
                    postLedgerEntry($pdo, $desc, $lines, $pid, $saleId, 'pos_sale', $date, $userId);
                    $out['revenue'] = true;
                } catch (Throwable $e) {
                    error_log("postPosSale revenue failed (sale $saleId): " . $e->getMessage());
                    $out['reason'] = 'revenue_post_error';
                }
            }
        } else { $out['revenue'] = true; $out['reason'] = 'already_posted'; }

        // ── Entry 2: COGS ──────────────────────────────────────────────────
        $cogs = posSaleCogs($pdo, $saleId);
        if ($cogs > 0 && !_sp_already_posted($pdo, 'pos_cogs', $saleId)) {
            $cogsAcc = cogsAccountId($pdo);
            $invAcc  = inventoryAccountId($pdo);
            if ($cogsAcc && $invAcc) {
                try {
                    postLedgerEntry($pdo, "$desc — cost of goods sold", [
                        ['account_id' => (int)$cogsAcc, 'type' => 'debit',  'amount' => $cogs, 'description' => 'COGS'],
                        ['account_id' => (int)$invAcc,  'type' => 'credit', 'amount' => $cogs, 'description' => 'Inventory reduction'],
                    ], $pid, $saleId, 'pos_cogs', $date, $userId);
                    $out['cogs'] = true;
                } catch (Throwable $e) {
                    error_log("postPosSale COGS failed (sale $saleId): " . $e->getMessage());
                }
            } elseif (!$out['reason']) {
                $out['reason'] = 'cogs_accounts_not_configured';
            }
        } elseif ($cogs > 0) { $out['cogs'] = true; }

        return $out;
    }
}

if (!function_exists('postPosReturn')) {
    /**
     * IN-6 — post the contra of a POS return (the returned portion). Never throws.
     *   Revenue contra: Dr Sales Returns (net) [+ Dr Output VAT (tax)] / Cr Cash/Bank
     *   COGS contra:    Dr Inventory / Cr COGS   (restocked cost)
     *
     * @return array ['revenue'=>bool,'cogs'=>bool,'reason'=>string]
     */
    function postPosReturn(
        PDO $pdo, int $returnId, string $refundMethod, float $refundGross, float $refundTax,
        float $restockCost, string $date, ?string $reference, ?int $projectId, int $userId
    ): array {
        $out = ['revenue' => false, 'cogs' => false, 'reason' => ''];
        if ($returnId <= 0 || $refundGross <= 0) { $out['reason'] = 'no_return'; return $out; }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;
        $refundTax = round($refundTax, 2); if ($refundTax < 0 || $refundTax > $refundGross) $refundTax = 0.0;
        $netReturn = round($refundGross - $refundTax, 2);
        $desc = "POS return " . ($reference ?: ('#' . $returnId));

        if (!_sp_already_posted($pdo, 'pos_return', $returnId)) {
            $sr   = salesReturnsAccountId($pdo) ?: salesRevenueAccountId($pdo);  // contra revenue
            $cash = posReceiptAccountId($pdo, $refundMethod);
            $vat  = ($refundTax > 0) ? outputVatAccountId($pdo) : null;
            if (!$sr || !$cash) {
                $out['reason'] = 'return_accounts_not_configured';
            } else {
                $lines = [['account_id' => (int)$sr, 'type' => 'debit', 'amount' => ($vat ? $netReturn : $refundGross), 'description' => 'Sales return']];
                if ($vat && $refundTax > 0) $lines[] = ['account_id' => (int)$vat, 'type' => 'debit', 'amount' => $refundTax, 'description' => 'Output VAT reversal'];
                $lines[] = ['account_id' => (int)$cash, 'type' => 'credit', 'amount' => $refundGross, 'description' => 'Refund'];
                try { postLedgerEntry($pdo, $desc, $lines, $pid, $returnId, 'pos_return', $date, $userId); $out['revenue'] = true; }
                catch (Throwable $e) { error_log("postPosReturn revenue failed (return $returnId): " . $e->getMessage()); $out['reason'] = 'return_post_error'; }
            }
        } else { $out['revenue'] = true; }

        $restockCost = round($restockCost, 2);
        if ($restockCost > 0 && !_sp_already_posted($pdo, 'pos_return_cogs', $returnId)) {
            $cogsAcc = cogsAccountId($pdo); $invAcc = inventoryAccountId($pdo);
            if ($cogsAcc && $invAcc) {
                try {
                    postLedgerEntry($pdo, "$desc — restock", [
                        ['account_id' => (int)$invAcc,  'type' => 'debit',  'amount' => $restockCost, 'description' => 'Inventory restocked'],
                        ['account_id' => (int)$cogsAcc, 'type' => 'credit', 'amount' => $restockCost, 'description' => 'COGS reversal'],
                    ], $pid, $returnId, 'pos_return_cogs', $date, $userId);
                    $out['cogs'] = true;
                } catch (Throwable $e) { error_log("postPosReturn COGS failed (return $returnId): " . $e->getMessage()); }
            }
        } elseif ($restockCost > 0) { $out['cogs'] = true; }

        return $out;
    }
}

if (!function_exists('creditNoteRestockCost')) {
    /**
     * Σ(quantity × products.cost_price) for a credit note's line items — the cost of
     * stocked goods coming back from a customer return. Mirrors posSaleCogs():
     *   - JOINs products, so free-text / service / price-adjustment lines (NULL or
     *     unmatched product_id) naturally contribute 0 — only real stocked goods
     *     reverse COGS, which is exactly the desired behaviour.
     *   - Skips a line whose cost_price exceeds its selling_price (selling_price > 0):
     *     a clear data-entry error that would inject a bogus COGS. Self-healing once
     *     the cost is corrected. Criteria-based — no product ids hard-coded.
     */
    function creditNoteRestockCost(PDO $pdo, int $creditNoteId): float
    {
        $v = $pdo->query("SELECT COALESCE(SUM(ci.quantity * COALESCE(p.cost_price,0)),0)
                            FROM credit_note_items ci
                            JOIN products p ON ci.product_id = p.product_id
                           WHERE ci.credit_note_id = " . (int)$creditNoteId . "
                             AND NOT (p.cost_price > p.selling_price AND p.selling_price > 0)")->fetchColumn();
        return round((float)$v, 2);
    }
}

if (!function_exists('postCreditNoteRestock')) {
    /**
     * Post the COGS contra for a settled credit note (customer return of stocked goods):
     *
     *     Dr Inventory  /  Cr Cost of Goods Sold        (restocked cost)
     *
     * the exact contra of the original sale's COGS, mirroring postPosReturn()'s restock
     * leg. This is the leg the credit-note (non-POS sales return) path was MISSING — so
     * Inventory was understated and COGS overstated on the statements. Paired with the
     * revenue/cash contra (Dr Sales Returns / Cr Cash) already posted at settlement, the
     * customer return is now fully double-entered.
     *
     * Best-effort: NEVER throws (the refund must record even if accounting can't post);
     * idempotent on (entity_type='credit_note_cogs', entity_id=credit_note_id) so paying
     * twice / re-running never double-posts; joins the caller's open transaction; never
     * touches accounts.current_balance.
     *
     * @return array ['posted'=>bool, 'reason'=>string, 'entry_id'?=>int]
     */
    function postCreditNoteRestock(PDO $pdo, int $creditNoteId, float $restockCost, string $date, ?int $projectId, int $userId): array
    {
        $out = ['posted' => false, 'reason' => ''];
        if ($creditNoteId <= 0) { $out['reason'] = 'invalid'; return $out; }

        $restockCost = round($restockCost, 2);
        if ($restockCost <= 0) { $out['reason'] = 'no_stock_cost'; return $out; }   // service / price-adjustment note → nothing to restock

        if ($existing = _sp_already_posted($pdo, 'credit_note_cogs', $creditNoteId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $cogsAcc = cogsAccountId($pdo);
        $invAcc  = inventoryAccountId($pdo);
        if (!$cogsAcc || !$invAcc) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;
        try {
            $entry = postLedgerEntry($pdo, "Credit note #$creditNoteId — restock (customer return)", [
                ['account_id' => (int)$invAcc,  'type' => 'debit',  'amount' => $restockCost, 'description' => 'Inventory restocked (customer return)'],
                ['account_id' => (int)$cogsAcc, 'type' => 'credit', 'amount' => $restockCost, 'description' => 'COGS reversal (customer return)'],
            ], $pid, $creditNoteId, 'credit_note_cogs', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postCreditNoteRestock failed (credit note $creditNoteId): " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reverseCreditNoteRestock')) {
    /**
     * Reverse a credit-note restock posting (symmetry for any future payment-reversal
     * path). Posts the contra (Dr COGS / Cr Inventory) via the shared reverser, keyed on
     * the same (entity_type='credit_note_cogs', entity_id) pair. Safe / idempotent.
     */
    function reverseCreditNoteRestock(PDO $pdo, int $creditNoteId, int $userId): array
    {
        require_once __DIR__ . '/expense_posting.php';   // reverseAccrualEntry (shared reversal)
        return reverseAccrualEntry($pdo, 'credit_note_cogs', $creditNoteId, $userId);
    }
}

if (!function_exists('postCreditNoteRefundVat')) {
    /**
     * account_financial.md #3 — a VAT credit-note refund must REVERSE the Output VAT
     * originally charged on the sale, not bury it in Sales Returns. Posts the 3-leg split:
     *
     *   Dr Sales Returns & Allowances (net = gross − tax)   ← contra-revenue
     *   Dr Output VAT Payable        (tax)                  ← reverses the VAT owed to TRA
     *      Cr Cash/Bank              (gross)                ← full refund paid to the customer
     *
     * Moves the Paid-From cash balance (mirror of postOutflow), and mirrors into the
     * canonical journal via recordGlobalTransaction. Use ONLY when tax > 0 and net > 0;
     * a no-VAT note keeps the original 2-leg postOutflow path (zero regression).
     *
     * @return int|null transaction_id, or null when it could not post.
     */
    function postCreditNoteRefundVat(PDO $pdo, string $ref, int $paidFromAccountId, int $sraAccountId,
                                     int $outputVatAccountId, float $gross, float $tax, string $date,
                                     string $desc, ?int $projectId, int $userId): ?int
    {
        require_once __DIR__ . '/payment_source.php';   // recordGlobalTransaction (via chain) + applyAccountBalanceDelta
        $gross = round($gross, 2); $tax = round($tax, 2); $net = round($gross - $tax, 2);
        if ($paidFromAccountId <= 0 || $sraAccountId <= 0 || $outputVatAccountId <= 0) return null;
        if ($gross <= 0 || $tax <= 0 || $net <= 0) return null;

        $res = recordGlobalTransaction([
            'transaction_date' => $date,
            'amount'           => $gross,
            'transaction_type' => 'credit_note_refund',
            'reference_number' => $ref,
            'description'      => $desc,
            'project_id'       => $projectId,
            'journal_items'    => [
                ['account_id' => $sraAccountId,        'type' => 'debit',  'amount' => $net,   'description' => $desc . ' (net of VAT)'],
                ['account_id' => $outputVatAccountId,  'type' => 'debit',  'amount' => $tax,   'description' => 'Output VAT reversed (sales return)'],
                ['account_id' => $paidFromAccountId,   'type' => 'credit', 'amount' => $gross, 'description' => $desc],
            ],
        ], $pdo);
        if (empty($res['success'])) return null;

        applyAccountBalanceDelta($pdo, $paidFromAccountId, 'credit', $gross);   // cash out (mirror of postOutflow)
        return (int)$res['transaction_id'];
    }
}
