<?php
/**
 * core/pos_credit_aging.php
 *
 * pos_credit_receivables_plan.md Phase 2 — the ONE shared source every
 * credit-receivables surface reads from (the dedicated "Who Owes Me" page,
 * the customer detail page's "Madeni" tab, and the dashboard.php "Credit"
 * card), so the same customer's numbers can never disagree between them.
 *
 * Deliberately mirrors core/pos_credit_limit.php's customerOutstandingBalance()
 * formula (grand_total - Σpayments, excluding voided/returns/fully-paid) —
 * this file does not replace that one, it builds on it.
 *
 * "On time" vs "late", precisely: a credit sale only counts toward either
 * bucket once it is FULLY settled (payment_status = 'paid') AND it actually
 * had a due_date recorded. Comparing its last payment's date against
 * due_date: last_payment_date <= due_date => on time, otherwise late. A
 * settled sale with no due_date (e.g. an Advanced-mode credit sale, which
 * never captures one) still counts toward "times borrowed" but is excluded
 * from the on-time/late split — there is nothing to judge it against.
 */

if (!function_exists('posCreditOpenSales')) {
    /**
     * Every still-open (not fully paid, not voided, not a return) credit
     * sale, one row per sale — the row set the aging page and the customer
     * Madeni tab both render (the tab just passes $customerId to filter to
     * one person).
     *
     * @return array<int, array{
     *   sale_id:int, receipt_number:string, customer_id:int,
     *   customer_name:string, customer_phone:?string,
     *   grand_total:float, paid:float, balance_due:float,
     *   sale_date:string, due_date:?string,
     *   days_elapsed:int, days_overdue:int, is_overdue:bool
     * }>
     */
    function posCreditOpenSales(PDO $pdo, ?int $customerId = null, string $scopeSql = ''): array
    {
        $params = [];
        $where = "s.payment_method = 'credit' AND s.is_return_sale = 0 AND s.sale_status NOT IN ('voided') AND s.payment_status != 'paid'";
        if ($customerId !== null) {
            $where .= " AND s.customer_id = ?";
            $params[] = $customerId;
        }

        $stmt = $pdo->prepare("
            SELECT
                s.sale_id, s.receipt_number, s.customer_id, s.warehouse_id,
                COALESCE(c.customer_name, s.customer_name) AS customer_name,
                COALESCE(c.phone, c.mobile, s.customer_phone) AS customer_phone,
                s.grand_total,
                COALESCE(p.paid, 0) AS paid,
                (s.grand_total - COALESCE(p.paid, 0)) AS balance_due,
                s.sale_date, s.due_date
            FROM pos_sales s
            LEFT JOIN customers c ON c.customer_id = s.customer_id
            LEFT JOIN (
                SELECT sale_id, SUM(amount) AS paid FROM pos_sale_payments GROUP BY sale_id
            ) p ON p.sale_id = s.sale_id
            WHERE $where
            $scopeSql
            ORDER BY (s.due_date IS NULL), s.due_date ASC, s.sale_date ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = new DateTime('today');
        foreach ($rows as &$r) {
            $r['grand_total']  = round((float)$r['grand_total'], 2);
            $r['paid']         = round((float)$r['paid'], 2);
            $r['balance_due']  = round((float)$r['balance_due'], 2);
            $saleDate = new DateTime(substr((string)$r['sale_date'], 0, 10));
            $r['days_elapsed'] = max(0, (int)$today->diff($saleDate)->format('%a'));
            $r['days_overdue'] = 0;
            $r['is_overdue']   = false;
            if (!empty($r['due_date'])) {
                $due = new DateTime($r['due_date']);
                if ($due < $today) {
                    $r['days_overdue'] = (int)$today->diff($due)->format('%a');
                    $r['is_overdue']   = true;
                }
            }
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('posCreditCustomerCounters')) {
    /**
     * Lifetime credit-history counters for one customer — feeds the "Madeni"
     * tab's stat row (times borrowed / repaid on time / repaid late) and its
     * currently-owed figure.
     *
     * @return array{times_borrowed:int, times_repaid_on_time:int, times_repaid_late:int, currently_owed:float}
     */
    function posCreditCustomerCounters(PDO $pdo, int $customerId): array
    {
        $out = ['times_borrowed' => 0, 'times_repaid_on_time' => 0, 'times_repaid_late' => 0, 'currently_owed' => 0.0];
        if ($customerId <= 0) return $out;

        $borrowed = $pdo->prepare("
            SELECT COUNT(*) FROM pos_sales
            WHERE customer_id = ? AND payment_method = 'credit'
              AND is_return_sale = 0 AND sale_status NOT IN ('voided')
        ");
        $borrowed->execute([$customerId]);
        $out['times_borrowed'] = (int)$borrowed->fetchColumn();

        // Settled credit sales that DID have a due date — their last payment
        // date decides on-time vs late.
        $settled = $pdo->prepare("
            SELECT s.sale_id, s.due_date, MAX(p.created_at) AS last_payment_at
            FROM pos_sales s
            JOIN pos_sale_payments p ON p.sale_id = s.sale_id
            WHERE s.customer_id = ? AND s.payment_method = 'credit'
              AND s.is_return_sale = 0 AND s.sale_status NOT IN ('voided')
              AND s.payment_status = 'paid' AND s.due_date IS NOT NULL
            GROUP BY s.sale_id, s.due_date
        ");
        $settled->execute([$customerId]);
        foreach ($settled->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lastPaymentDate = substr((string)$row['last_payment_at'], 0, 10);
            if ($lastPaymentDate !== '' && $lastPaymentDate <= $row['due_date']) {
                $out['times_repaid_on_time']++;
            } else {
                $out['times_repaid_late']++;
            }
        }

        $out['currently_owed'] = customerOutstandingBalance($pdo, $customerId);
        return $out;
    }
}

if (!function_exists('posCreditTotalOutstanding')) {
    /**
     * Tenant-wide (or scoped) total still owed across every open credit
     * sale — the dashboard.php "Credit"/"Madeni" card's headline figure.
     */
    function posCreditTotalOutstanding(PDO $pdo, string $scopeSql = ''): float
    {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(s.grand_total - COALESCE(p.paid, 0)), 0)
            FROM pos_sales s
            LEFT JOIN (
                SELECT sale_id, SUM(amount) AS paid FROM pos_sale_payments GROUP BY sale_id
            ) p ON p.sale_id = s.sale_id
            WHERE s.payment_method = 'credit'
              AND s.is_return_sale = 0
              AND s.sale_status NOT IN ('voided')
              AND s.payment_status != 'paid'
            $scopeSql
        ");
        $stmt->execute();
        return round((float)$stmt->fetchColumn(), 2);
    }
}
