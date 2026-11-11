<?php
/**
 * core/pos_receipt_returns.php — what a POS receipt shows once goods came back (2026-11-11).
 *
 * One source for the three ways a receipt leaves the system (browser print, network
 * printer, emailed PDF), so they can never disagree:
 *
 *  - An ORIGINAL sale that had returns keeps every line, but a returned line shows the
 *    sold quantity crossed out and what remains (3 → 2), and its price is the amount for
 *    what remains. The totals become: Sold total, one "Refunded (RET-0001)" line per
 *    refund, NET PAID, and a PARTIALLY REFUNDED / REFUNDED stamp.
 *  - A RETURN row (is_return_sale = 1) prints as a refund receipt for its original.
 *
 * Display only: amounts come from the rows create_return.php already wrote (the return
 * header's grand_total, each line's returned_quantity). Nothing here writes.
 */

if (!function_exists('posRefundShortNo')) {
    /** "BJP-RET-0001" → "RET-0001" (the receipt line is narrow). Any other number is kept as it is. */
    function posRefundShortNo(string $no): string
    {
        return preg_match('/^[A-Z0-9]{1,10}-(RET-\d+)$/', $no, $m) ? $m[1] : $no;
    }
}

if (!function_exists('posReceiptReturns')) {
    /**
     * @param array $sale  the pos_sales row (needs sale_id, is_return_sale, original_sale_id,
     *                     receipt_number, grand_total, sale_status, payment_method, return_reason)
     * @param array $items its pos_sale_items rows (needs quantity, returned_quantity, line_total)
     * @return array{
     *   mode: 'plain'|'returns'|'refund',
     *   lines: array<int, array{sold: float, kept: float, returned: float, kept_total: float}>,
     *   refunds: array<int, array{sale_id:int, receipt_number:string, short:string, amount:float}>,
     *   sold_total: float, refunded_total: float, net_paid: float, stamp: string,
     *   original_receipt: string, reason: string, method: string, to_balance: float, cash_back: float
     * }
     */
    function posReceiptReturns(PDO $pdo, array $sale, array $items): array
    {
        $out = [
            'mode' => 'plain', 'lines' => [], 'refunds' => [],
            'sold_total' => round((float)($sale['grand_total'] ?? 0), 2), 'refunded_total' => 0.0, 'net_paid' => 0.0,
            'stamp' => '', 'original_receipt' => '', 'reason' => '', 'method' => '', 'to_balance' => 0.0, 'cash_back' => 0.0,
        ];

        // ── A refund receipt (the return row itself) ─────────────────────────
        if ((int)($sale['is_return_sale'] ?? 0) === 1) {
            $out['mode']   = 'refund';
            $out['reason'] = trim((string)($sale['return_reason'] ?? ''));
            $out['method'] = (string)($sale['payment_method'] ?? '');
            $origId = (int)($sale['original_sale_id'] ?? 0);
            if ($origId > 0) {
                $st = $pdo->prepare("SELECT receipt_number FROM pos_sales WHERE sale_id = ?");
                $st->execute([$origId]);
                $out['original_receipt'] = (string)($st->fetchColumn() ?: '');
                // Part of this refund may have come off what the customer still owed (a credit sale):
                // create_return.php records that as a 'return_credit' payment on the original, referenced
                // by this refund's number.
                try {
                    $b = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM pos_sale_payments
                                         WHERE sale_id = ? AND payment_method = 'return_credit' AND reference = ?");
                    $b->execute([$origId, (string)$sale['receipt_number']]);
                    $out['to_balance'] = round((float)$b->fetchColumn(), 2);
                } catch (Throwable $e) {
                    $out['to_balance'] = 0.0;   // table/column not there yet — whole amount was paid back
                }
            }
            $out['to_balance'] = min($out['to_balance'], $out['sold_total']);
            $out['cash_back']  = round($out['sold_total'] - $out['to_balance'], 2);
            return $out;
        }

        // ── An original sale: did anything come back? ───────────────────────
        $anyReturned = false;
        foreach ($items as $i => $it) {
            $sold = (float)($it['quantity'] ?? 0);
            $ret  = min(max(0.0, (float)($it['returned_quantity'] ?? 0)), $sold);
            $kept = round($sold - $ret, 3);
            $out['lines'][$i] = [
                'sold' => $sold, 'returned' => $ret, 'kept' => $kept,
                'kept_total' => $sold > 0 ? round((float)($it['line_total'] ?? 0) * $kept / $sold, 2) : 0.0,
            ];
            if ($ret > 0.0000001) $anyReturned = true;
        }

        $rs = $pdo->prepare("SELECT sale_id, receipt_number, grand_total
                               FROM pos_sales
                              WHERE original_sale_id = ? AND is_return_sale = 1
                                AND sale_status NOT IN ('voided','cancelled')
                           ORDER BY sale_id");
        $rs->execute([(int)$sale['sale_id']]);
        foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $amt = round((float)$r['grand_total'], 2);
            $out['refunds'][] = [
                'sale_id' => (int)$r['sale_id'], 'receipt_number' => (string)$r['receipt_number'],
                'short' => posRefundShortNo((string)$r['receipt_number']), 'amount' => $amt,
            ];
            $out['refunded_total'] += $amt;
        }
        $out['refunded_total'] = round($out['refunded_total'], 2);

        if (!$anyReturned && !$out['refunds']) return $out;   // a normal receipt — unchanged

        $out['mode']     = 'returns';
        $out['net_paid'] = round($out['sold_total'] - $out['refunded_total'], 2);
        $allBack = true;
        foreach ($out['lines'] as $l) { if ($l['kept'] > 0.0000001) { $allBack = false; break; } }
        $out['stamp'] = ($allBack || ($sale['sale_status'] ?? '') === 'refunded') ? 'REFUNDED' : 'PARTIALLY REFUNDED';
        return $out;
    }
}
