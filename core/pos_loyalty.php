<?php
/**
 * core/pos_loyalty.php
 * ---------------------
 * Phase 11 (pos_upgrade_plan.md §7) — POS loyalty points: real accrual and
 * redemption, replacing the two unused pos_sales.loyalty_points_earned/
 * loyalty_points_redeemed columns that nothing ever wrote to.
 *
 * Design:
 *   - customer_loyalty_transactions is the ledger of truth (mirrors
 *     stock_movements) — every earn/redeem/reversal is an auditable row.
 *   - customers.loyalty_points_balance is a denormalised cache kept in sync
 *     with the ledger inside the same DB transaction (mirrors
 *     products.stock_quantity alongside stock_movements).
 *   - Redemption locks the customer row (FOR UPDATE) so two tills redeeming
 *     the same customer's points at the same moment can't both succeed against
 *     a balance that only supports one of them.
 *   - Unlike GL posting (core/sales_posting.php), this is NOT best-effort — it
 *     is core internal DB logic with no external dependency (no chart-of-
 *     accounts-style admin configuration to be missing), so a failure here
 *     throws and rolls back the sale with it, same as any other internal
 *     invariant.
 *   - Not wired into partial returns (create_return.php) — proportional point
 *     clawback on a partial return is a real edge case, deliberately left as a
 *     documented gap rather than a fragile guess. A full void DOES reverse
 *     both earn and redeem in full, matching void's existing all-or-nothing
 *     "as if it never happened" semantics.
 */

if (!function_exists('loyaltySettings')) {
    function loyaltySettings(): array
    {
        return [
            'enabled'       => getSetting('pos_loyalty_enabled', '0') === '1',
            // "1 point earned per this many currency units spent" — e.g. 1000 => 1 point per 1,000 TZS.
            'spend_per_point' => max(0.01, (float)getSetting('pos_loyalty_spend_per_point', '1000')),
            // Currency value of ONE point when redeemed — e.g. 100 => 1 point knocks 100 TZS off the total.
            'redeem_value'  => max(0.0, (float)getSetting('pos_loyalty_redeem_value', '50')),
        ];
    }
}

if (!function_exists('loyaltyPointsForAmount')) {
    /** Points earned for a given spend, floor-rounded (never round up a customer's favour into a bigger reward than earned). */
    function loyaltyPointsForAmount(float $amount, float $spendPerPoint): int
    {
        if ($amount <= 0 || $spendPerPoint <= 0) return 0;
        return (int)floor($amount / $spendPerPoint);
    }
}

if (!function_exists('awardLoyaltyPoints')) {
    /**
     * Earn points for a completed sale. No-op (returns 0) when the program is
     * disabled, the sale has no registered customer (walk-in can't accrue),
     * or the amount earns less than 1 point. Joins the caller's transaction.
     * $cfg overrides loyaltySettings() — used by tests so they don't depend on
     * helpers.php::get_setting()'s process-wide static cache.
     */
    function awardLoyaltyPoints(PDO $pdo, ?int $customerId, int $saleId, float $grandTotal, int $userId, ?array $cfg = null): int
    {
        if (!$customerId) return 0;
        $cfg = $cfg ?? loyaltySettings();
        if (!$cfg['enabled']) return 0;

        $points = loyaltyPointsForAmount($grandTotal, $cfg['spend_per_point']);
        if ($points <= 0) return 0;

        $pdo->prepare("UPDATE customers SET loyalty_points_balance = loyalty_points_balance + ? WHERE customer_id = ?")
            ->execute([$points, $customerId]);
        $balance = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = " . (int)$customerId)->fetchColumn();

        $pdo->prepare("INSERT INTO customer_loyalty_transactions (customer_id, sale_id, txn_type, points, balance_after, created_by, created_at)
                        VALUES (?, ?, 'earn', ?, ?, ?, NOW())")
            ->execute([$customerId, $saleId, $points, $balance, $userId]);

        return $points;
    }
}

if (!function_exists('redeemLoyaltyPoints')) {
    /**
     * Redeem points at checkout for a discount. Validates against the REAL
     * balance server-side (never trusts the client's idea of it) under a row
     * lock, so concurrent redemption at two tills can't both succeed past the
     * true balance. $cfg overrides loyaltySettings() (see awardLoyaltyPoints()).
     *
     * @return array{points:int,discount:float,error:?string}
     */
    function redeemLoyaltyPoints(PDO $pdo, ?int $customerId, int $pointsRequested, int $saleId, int $userId, ?array $cfg = null): array
    {
        $out = ['points' => 0, 'discount' => 0.0, 'error' => null];
        if ($pointsRequested <= 0) return $out;
        if (!$customerId) { $out['error'] = 'Points can only be redeemed against a registered customer, not a walk-in sale.'; return $out; }

        $cfg = $cfg ?? loyaltySettings();
        if (!$cfg['enabled']) { $out['error'] = 'Loyalty points are not enabled.'; return $out; }

        $balance = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = " . (int)$customerId . " FOR UPDATE")->fetchColumn();
        $points = min($pointsRequested, $balance);
        if ($points <= 0) { $out['error'] = 'This customer has no loyalty points to redeem.'; return $out; }

        $discount = round($points * $cfg['redeem_value'], 2);

        $pdo->prepare("UPDATE customers SET loyalty_points_balance = loyalty_points_balance - ? WHERE customer_id = ?")
            ->execute([$points, $customerId]);
        $newBalance = $balance - $points;

        $pdo->prepare("INSERT INTO customer_loyalty_transactions (customer_id, sale_id, txn_type, points, balance_after, created_by, created_at)
                        VALUES (?, ?, 'redeem', ?, ?, ?, NOW())")
            ->execute([$customerId, $saleId, $points, $newBalance, $userId]);

        $out['points'] = $points;
        $out['discount'] = $discount;
        return $out;
    }
}

if (!function_exists('reverseLoyaltyForSale')) {
    /**
     * Reverse every earn/redeem this sale caused (used by void_sale.php — a
     * void is "as if the sale never happened" for stock and cash already, so
     * loyalty follows the same rule). Idempotent: a sale already reversed for
     * a given direction is skipped, not double-reversed.
     */
    function reverseLoyaltyForSale(PDO $pdo, int $saleId, int $userId): void
    {
        $rows = $pdo->prepare("SELECT customer_id, txn_type, points FROM customer_loyalty_transactions
                                 WHERE sale_id = ? AND txn_type IN ('earn','redeem')");
        $rows->execute([$saleId]);
        $txns = $rows->fetchAll(PDO::FETCH_ASSOC);
        if (!$txns) return;

        $already = $pdo->prepare("SELECT 1 FROM customer_loyalty_transactions WHERE sale_id = ? AND txn_type = ? LIMIT 1");

        foreach ($txns as $t) {
            $reversalType = $t['txn_type'] === 'earn' ? 'earn_reversal' : 'redeem_reversal';
            $already->execute([$saleId, $reversalType]);
            if ($already->fetchColumn()) continue;   // already reversed — idempotent

            $customerId = (int)$t['customer_id'];
            $points     = (int)$t['points'];
            // Reversing an 'earn' removes points; reversing a 'redeem' gives them back.
            $delta = $t['txn_type'] === 'earn' ? -$points : $points;

            $pdo->prepare("UPDATE customers SET loyalty_points_balance = GREATEST(0, loyalty_points_balance + ?) WHERE customer_id = ?")
                ->execute([$delta, $customerId]);
            $balance = (int)$pdo->query("SELECT loyalty_points_balance FROM customers WHERE customer_id = " . $customerId)->fetchColumn();

            $pdo->prepare("INSERT INTO customer_loyalty_transactions (customer_id, sale_id, txn_type, points, balance_after, created_by, notes, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, 'Reversed by void', NOW())")
                ->execute([$customerId, $saleId, $reversalType, $points, $balance, $userId]);
        }
    }
}
