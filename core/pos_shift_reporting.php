<?php
/**
 * core/pos_shift_reporting.php
 * ----------------------------
 * Phase 8 (pos_upgrade_plan.md §7) — shift-close tender-breakdown reporting.
 * Extracted out of api/pos/close_shift.php so it's independently unit-testable
 * and reusable by a future Z-report (Phase 9) without duplicating the logic.
 */

if (!function_exists('buildSplitPaymentLegs')) {
    /**
     * Normalise a split-payment modal's leg amounts (cash/mobile/bank/card — see
     * pos_scripts_new.php::processSplitPayment) into the JSON stored on
     * pos_sales.payment_details, keyed by the real payment_method names (the
     * pos_sale_payments enum: cash/card/mobile_money/bank_transfer). Returns null
     * for a non-split sale or an empty/invalid split — payment_details should
     * only ever describe an actual multi-tender split.
     */
    function buildSplitPaymentLegs(?array $splitDetails): ?string
    {
        if (!$splitDetails) return null;
        $keyMap = ['cash' => 'cash', 'card' => 'card', 'mobile' => 'mobile_money', 'bank' => 'bank_transfer'];
        $legs = [];
        foreach ($splitDetails as $k => $v) {
            $amt = round((float)$v, 2);
            $method = $keyMap[$k] ?? null;
            if ($method && $amt > 0.001) $legs[$method] = ($legs[$method] ?? 0) + $amt;
        }
        return $legs ? json_encode(['legs' => $legs], JSON_UNESCAPED_SLASHES) : null;
    }
}

if (!function_exists('posShiftTenderTotals')) {
    /**
     * Compute the tender-breakdown totals for one cash-register shift, straight
     * from pos_sales (the source of truth for what was actually sold) — NOT from
     * cash_register_transactions, which only ever logs the CASH leg of a sale and
     * so cannot answer "how much card/mobile/credit did this shift do".
     *
     * A voided sale is excluded entirely (never happened). A return
     * (is_return_sale=1) counts only toward total_refunds, regardless of which
     * shift the original sale was in — refunds are attributed to the shift the
     * refund itself was processed in, matching create_return.php's own shift
     * assignment. A 'mixed' (split-tender) sale has its payment_details JSON
     * unpacked leg-by-leg so its portions land in the correct bucket instead of
     * being attributed to a single method.
     *
     * bank_transfer/voucher/loyalty_points tenders have no dedicated bucket on
     * this schema (only cash/card/mobile/credit exist) — they still count in
     * total_sales, just not broken out individually.
     *
     * @return array{total_sales:float,total_cash_sales:float,total_card_sales:float,total_mobile_sales:float,total_credit_sales:float,total_refunds:float}
     */
    function posShiftTenderTotals(PDO $pdo, int $shiftId): array
    {
        $out = ['total_sales' => 0.0, 'total_cash_sales' => 0.0, 'total_card_sales' => 0.0,
                'total_mobile_sales' => 0.0, 'total_credit_sales' => 0.0, 'total_refunds' => 0.0];

        $stmt = $pdo->prepare("SELECT payment_method, grand_total, is_return_sale, payment_details
                                  FROM pos_sales WHERE shift_id = ? AND sale_status != 'voided'");
        $stmt->execute([$shiftId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amt = (float)$row['grand_total'];
            if ((int)$row['is_return_sale'] === 1) {
                $out['total_refunds'] += $amt;
                continue;
            }
            $out['total_sales'] += $amt;
            if ($row['payment_method'] === 'mixed' && $row['payment_details']) {
                $legs = json_decode($row['payment_details'], true)['legs'] ?? [];
                foreach ($legs as $method => $legAmt) {
                    if ($method === 'cash')             $out['total_cash_sales']   += (float)$legAmt;
                    elseif ($method === 'card')         $out['total_card_sales']   += (float)$legAmt;
                    elseif ($method === 'mobile_money') $out['total_mobile_sales'] += (float)$legAmt;
                }
            } elseif ($row['payment_method'] === 'cash')         $out['total_cash_sales']   += $amt;
            elseif ($row['payment_method'] === 'card')           $out['total_card_sales']   += $amt;
            elseif ($row['payment_method'] === 'mobile_money')   $out['total_mobile_sales'] += $amt;
            elseif ($row['payment_method'] === 'credit')         $out['total_credit_sales'] += $amt;
        }

        foreach ($out as $k => $v) $out[$k] = round($v, 2);
        return $out;
    }
}

if (!function_exists('posShiftReportExtras')) {
    /**
     * The Z-Report (Phase 9) figures that sit alongside posShiftTenderTotals():
     * void summary, return count, and GL posting health (how many of this
     * shift's completed sales have no posted 'pos_sale' journal entry —
     * postPosSale() is best-effort and never blocks a sale, so a misconfigured
     * chart of accounts can leave a sale silently un-posted).
     *
     * @return array{void_count:int,void_amount:float,return_count:int,unposted_count:int}
     */
    function posShiftReportExtras(PDO $pdo, int $shiftId): array
    {
        $voidStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(grand_total),0) AS amt
                                      FROM pos_sales WHERE shift_id = ? AND sale_status = 'voided'");
        $voidStmt->execute([$shiftId]);
        $void = $voidStmt->fetch(PDO::FETCH_ASSOC);

        $returnStmt = $pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE shift_id = ? AND is_return_sale = 1 AND sale_status != 'voided'");
        $returnStmt->execute([$shiftId]);

        $glStmt = $pdo->prepare("
            SELECT COUNT(*) FROM pos_sales s
             WHERE s.shift_id = ? AND s.is_return_sale = 0 AND s.sale_status != 'voided'
               AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.entity_type = 'pos_sale' AND je.entity_id = s.sale_id AND je.status = 'posted')
        ");
        $glStmt->execute([$shiftId]);

        return [
            'void_count'     => (int)$void['cnt'],
            'void_amount'    => round((float)$void['amt'], 2),
            'return_count'   => (int)$returnStmt->fetchColumn(),
            'unposted_count' => (int)$glStmt->fetchColumn(),
        ];
    }
}

if (!function_exists('posSaleDrawerCashOwed')) {
    /**
     * How much cash a sale still has sitting in the cash drawer ledger.
     *
     * Reads cash_register_transactions, not pos_sales: the drawer ledger is what
     * the POS header's "Cash Balance" sums, so reversing a sale against it keeps
     * that figure honest whatever the sale's payment method was. A credit sale
     * that took a deposit, a split sale and a settled credit sale all leave cash
     * in the drawer even though pos_sales.payment_method is not 'cash'.
     *
     * received = cash the drawer took in for this sale (the sale row + any later
     *            'cash_in' settlements). Rows are matched by sale_id; a split
     *            sale's row has no sale_id, so it is matched by receipt number.
     * refunded = what earlier voids already took back out (reason 'Void: …').
     *
     * @return array{received: float, refunded: float, owed: float}
     */
    function posSaleDrawerCashOwed(PDO $pdo, int $saleId, string $receiptNumber): array
    {
        $byReceipt = $receiptNumber !== '' ? ' OR (sale_id IS NULL AND reference_number = ?)' : '';
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN transaction_type IN ('sale', 'cash_in') AND payment_method = 'cash' THEN amount ELSE 0 END), 0) AS received,
                COALESCE(SUM(CASE WHEN transaction_type = 'refund' AND reason LIKE 'Void:%' THEN amount ELSE 0 END), 0) AS refunded
              FROM cash_register_transactions
             WHERE sale_id = ?{$byReceipt}
        ");
        $stmt->execute($receiptNumber !== '' ? [$saleId, $receiptNumber] : [$saleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $received = round((float)($row['received'] ?? 0), 2);
        $refunded = round((float)($row['refunded'] ?? 0), 2);
        return ['received' => $received, 'refunded' => $refunded, 'owed' => max(0.0, round($received - $refunded, 2))];
    }
}

if (!function_exists('posFixVoidedSaleDrawerCash')) {
    /**
     * One-off repair for sales voided before void_sale.php took back every cash
     * leg: adds the missing 'Void:' refund to each OPEN shift's drawer ledger.
     * Closed shifts are left alone — they were reconciled at close.
     *
     * Idempotent: the amount comes from posSaleDrawerCashOwed(), which counts the
     * refunds already there, so a second run finds nothing owed.
     *
     * @return int number of corrective refund rows added
     */
    function posFixVoidedSaleDrawerCash(PDO $pdo): int
    {
        // Cash the drawer took in during OPEN shifts — a small set. Sales are looked
        // up per row (by id, or by receipt for a split sale's row that has no
        // sale_id) rather than joined in SQL, because pos_sales and the drawer
        // table do not share a collation on every database.
        $rows = $pdo->query("
            SELECT c.shift_id, c.sale_id, c.reference_number, sh.user_id AS shift_user
              FROM cash_register_transactions c
              JOIN cash_register_shifts sh ON sh.shift_id = c.shift_id AND sh.status = 'active'
             WHERE c.transaction_type IN ('sale', 'cash_in') AND c.payment_method = 'cash'
          ORDER BY c.transaction_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $byId      = $pdo->prepare("SELECT sale_id, receipt_number, sale_status, is_return_sale, voided_by FROM pos_sales WHERE sale_id = ?");
        $byReceipt = $pdo->prepare("SELECT sale_id, receipt_number, sale_status, is_return_sale, voided_by FROM pos_sales WHERE receipt_number = ?");
        $insert    = $pdo->prepare("
            INSERT INTO cash_register_transactions
                   (shift_id, transaction_type, amount, payment_method, reference_number, sale_id, reason, created_by, created_at)
            VALUES (?, 'refund', ?, 'cash', ?, ?, 'Void: cash taken at the till returned (auto-correction)', ?, NOW())
        ");
        $added = 0;
        foreach ($rows as $r) {
            if ($r['sale_id'] !== null) {
                $byId->execute([(int)$r['sale_id']]);
                $sale = $byId->fetch(PDO::FETCH_ASSOC);
            } elseif ((string)$r['reference_number'] !== '') {
                $byReceipt->execute([(string)$r['reference_number']]);
                $sale = $byReceipt->fetch(PDO::FETCH_ASSOC);
            } else {
                continue;
            }
            if (!$sale || $sale['sale_status'] !== 'voided' || (int)$sale['is_return_sale'] === 1) continue;

            $owed = posSaleDrawerCashOwed($pdo, (int)$sale['sale_id'], (string)$sale['receipt_number'])['owed'];
            if ($owed <= 0) continue;
            $actor = (int)($sale['voided_by'] ?: $r['shift_user']);
            $insert->execute([(int)$r['shift_id'], $owed, $sale['receipt_number'], (int)$sale['sale_id'], $actor]);
            $added++;
        }
        return $added;
    }
}
