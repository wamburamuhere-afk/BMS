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
     * The cash/bank GL account a POS tender lands in, by payment method (existing accounts):
     *   cash / voucher / loyalty_points → Cash Drawer (1-1130)
     *   mobile_money                    → Electronic Payments (1-1190)
     *   card / bank_transfer            → Cheque/Bank (1-1110)
     * Falls back to the first active cash/bank leaf. Method is metadata; this only picks
     * which real account the money sits in.
     */
    function posReceiptAccountId(PDO $pdo, string $method): ?int
    {
        $code = match ($method) {
            'mobile_money'                 => '1-1190',
            'card', 'bank_transfer'        => '1-1110',
            default                        => '1-1130',   // cash, voucher, loyalty_points, split, other
        };
        $v = gl_account_by_code($pdo, $code);
        if ($v && gl_account_active($pdo, $v)) return $v;
        // fallback: first active cash/bank leaf
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
    /** Σ(quantity × products.cost_price) for a POS sale — the existing Income-Statement convention. */
    function posSaleCogs(PDO $pdo, int $saleId): float
    {
        $v = $pdo->query("SELECT COALESCE(SUM(si.quantity * COALESCE(p.cost_price,0)),0)
                            FROM pos_sale_items si
                            JOIN products p ON si.product_id = p.product_id
                           WHERE si.sale_id = " . (int)$saleId)->fetchColumn();
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
