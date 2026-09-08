<?php
/**
 * core/pos_credit_limit.php
 *
 * Phase 19 (pos_upgrade_plan.md §8) — customer credit-limit enforcement at
 * POS. customers.credit_limit already existed (captured on the customer
 * form) but nothing at the point of sale ever checked it. Extracted for
 * independent testability, same reasoning as every other core/pos_*.php
 * helper added in this tranche.
 */

/**
 * Sum of every non-voided, non-fully-paid POS sale's remaining balance for
 * one customer — the same per-sale formula api/pos/receive_payment.php
 * already uses (grand_total - Σpayments), summed across all their open sales.
 * A completed sale currently being finalised (mid-transaction) is not yet
 * committed/visible to this query, so it must be added by the caller.
 */
function customerOutstandingBalance(PDO $pdo, int $customerId): float
{
    if ($customerId <= 0) return 0.0;

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(s.grand_total - COALESCE(p.paid, 0)), 0)
        FROM pos_sales s
        LEFT JOIN (
            SELECT sale_id, SUM(amount) AS paid FROM pos_sale_payments GROUP BY sale_id
        ) p ON p.sale_id = s.sale_id
        WHERE s.customer_id = ?
          AND s.is_return_sale = 0
          AND s.sale_status NOT IN ('voided')
          AND s.payment_status != 'paid'
    ");
    $stmt->execute([$customerId]);
    return round((float)$stmt->fetchColumn(), 2);
}

/**
 * A dedicated exception type so the client can reliably detect "blocked by
 * the credit limit" (to offer a manager an override retry) without
 * string-matching a translated error message, which would break under any
 * language other than the one it was written in.
 */
class PosCreditLimitExceededException extends Exception {}

/**
 * Throws if adding $newBalanceDue to the customer's existing outstanding
 * balance would exceed their credit_limit — UNLESS $overridePermitted.
 * A credit_limit of 0 (the column's own DEFAULT) means "no credit allowed
 * at all", consistent with how the column already behaves everywhere else.
 * A no-op when $newBalanceDue is 0 (the "credit" sale is actually being
 * paid in full via a deposit — no real credit exposure).
 *
 * @throws Exception
 */
function assertPosCreditLimitPermitted(PDO $pdo, int $customerId, float $newBalanceDue, bool $overridePermitted, string $customerName): void
{
    if ($newBalanceDue <= 0.01 || $customerId <= 0) return;

    $stmt = $pdo->prepare("SELECT credit_limit FROM customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $creditLimit = (float)($stmt->fetchColumn() ?: 0);

    $existingOutstanding = customerOutstandingBalance($pdo, $customerId);
    $projected = round($existingOutstanding + $newBalanceDue, 2);

    if ($projected > $creditLimit + 0.01 && !$overridePermitted) {
        throw new PosCreditLimitExceededException(sprintf(
            t('Credit limit exceeded for %s: outstanding %s + this sale %s would total %s, over their limit of %s.'),
            $customerName,
            number_format($existingOutstanding, 2),
            number_format($newBalanceDue, 2),
            number_format($projected, 2),
            number_format($creditLimit, 2)
        ));
    }
}
