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
