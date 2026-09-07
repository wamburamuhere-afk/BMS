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
