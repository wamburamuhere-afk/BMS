<?php
/**
 * core/pos_credit_receipt.php
 * ---------------------------
 * 2026-11-17 — what a credit sale's receipt shows: how much has been paid (and how), what is
 * still owed and by when. Read from pos_sale_payments (the deposit at sale, every later
 * "Receive Payment", and goods returned against the balance), so a reprint always shows the
 * current position. Used by the browser receipt and the network (ESC/POS) printer.
 *
 * A credit sale used to print "Payment (Credit): <the whole total>, Change: 0" — as if paid.
 */

if (!function_exists('posCreditReceiptInfo')) {
    /**
     * @return array{paid:float, returned:float, balance:float, due_date:?string, methods:string[]}|null
     *         null when the sale is not a credit sale (or is a return row).
     */
    function posCreditReceiptInfo(PDO $pdo, array $sale): ?array
    {
        if (($sale['payment_method'] ?? '') !== 'credit' || (int)($sale['is_return_sale'] ?? 0) === 1) return null;
        $paid = 0.0; $returned = 0.0; $methods = [];
        try {
            $st = $pdo->prepare("SELECT amount, payment_method FROM pos_sale_payments WHERE sale_id = ? ORDER BY payment_id");
            $st->execute([(int)$sale['sale_id']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
                if ($p['payment_method'] === 'return_credit') { $returned += (float)$p['amount']; continue; }
                $paid += (float)$p['amount'];
                if (!in_array($p['payment_method'], $methods, true)) $methods[] = $p['payment_method'];
            }
        } catch (Throwable $e) { /* no payments table on this database — nothing paid on record */ }
        $balance = max(0.0, round((float)$sale['grand_total'] - $paid - $returned, 2));
        $due = !empty($sale['due_date']) ? substr((string)$sale['due_date'], 0, 10) : null;
        return ['paid' => round($paid, 2), 'returned' => round($returned, 2), 'balance' => $balance, 'due_date' => $due, 'methods' => $methods];
    }
}
