<?php
/**
 * core/receivables_payables.php
 * -----------------------------
 * Document-derived AR / AP / accrued positions for the Balance Sheet, mirroring the
 * drift-proof VAT (core/vat.php) and WHT (core/wht.php) injection pattern: each figure
 * is summed live from the source documents, so it never disagrees with the operational
 * records and needs no GL postings.
 *
 * Recognition rule (per agreed scope): EVERY status except cancelled / rejected /
 * deleted (and draft, which is an un-saved working state). The UNPAID balance of those
 * documents becomes a Balance-Sheet asset (money owed to us) or liability (money we owe).
 *
 * Every function is guarded so a server missing a table (older deploy) returns 0 rather
 * than throwing — identical resilience to the VAT/WHT helpers.
 */

if (!function_exists('rp_table_exists')) {
    function rp_table_exists(PDO $pdo, string $t): bool {
        try { return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch(); }
        catch (Throwable $e) { return false; }
    }
}

if (!function_exists('arInvoicesPosition')) {
    /**
     * Accounts Receivable — the unpaid balance of customer invoices (a current ASSET).
     * Paid invoices have balance_due = 0, so they naturally contribute nothing.
     * @return array{receivable: float}
     */
    function arInvoicesPosition(PDO $pdo): array {
        if (!rp_table_exists($pdo, 'invoices')) return ['receivable' => 0.0];
        try {
            $v = $pdo->query("
                SELECT COALESCE(SUM(GREATEST(COALESCE(balance_due, grand_total - COALESCE(paid_amount,0)), 0)), 0)
                  FROM invoices
                 WHERE status NOT IN ('cancelled','rejected','deleted','draft')
            ")->fetchColumn();
            return ['receivable' => round((float)$v, 2)];
        } catch (Throwable $e) { return ['receivable' => 0.0]; }
    }
}

if (!function_exists('apSupplierInvoicesPosition')) {
    /**
     * Accounts Payable — unpaid supplier / received invoices (a current LIABILITY).
     * supplier_invoices has no partial-payment tracking, so an invoice is either fully
     * outstanding (status not 'paid') or settled.
     * @return array{payable: float}
     */
    function apSupplierInvoicesPosition(PDO $pdo): array {
        if (!rp_table_exists($pdo, 'supplier_invoices')) return ['payable' => 0.0];
        try {
            $v = $pdo->query("
                SELECT COALESCE(SUM(amount), 0)
                  FROM supplier_invoices
                 WHERE status NOT IN ('paid','cancelled','rejected','deleted','draft')
            ")->fetchColumn();
            return ['payable' => round((float)$v, 2)];
        } catch (Throwable $e) { return ['payable' => 0.0]; }
    }
}

if (!function_exists('accruedExpensesPosition')) {
    /**
     * Accrued Expenses — expenses incurred (recognised on the P&L) but not yet paid
     * (a current LIABILITY). Excludes payroll-linked rows (handled via Salaries Payable).
     * @return array{payable: float}
     */
    function accruedExpensesPosition(PDO $pdo): array {
        if (!rp_table_exists($pdo, 'expenses')) return ['payable' => 0.0];
        try {
            $v = $pdo->query("
                SELECT COALESCE(SUM(amount), 0)
                  FROM expenses
                 WHERE status NOT IN ('paid','cancelled','rejected','deleted','draft')
                   AND payroll_id IS NULL
            ")->fetchColumn();
            return ['payable' => round((float)$v, 2)];
        } catch (Throwable $e) { return ['payable' => 0.0]; }
    }
}

if (!function_exists('salariesPayablePosition')) {
    /**
     * Salaries Payable — net pay of payroll that is recognised (accrual) but not yet
     * paid (a current LIABILITY). Mirrors the income statement: every payroll except
     * cancelled/rejected; the unpaid ones (payment_status <> 'paid') are owed to staff.
     * @return array{payable: float}
     */
    function salariesPayablePosition(PDO $pdo): array {
        if (!rp_table_exists($pdo, 'payroll')) return ['payable' => 0.0];
        try {
            $v = $pdo->query("
                SELECT COALESCE(SUM(net_salary), 0)
                  FROM payroll
                 WHERE payment_status NOT IN ('paid','cancelled','rejected')
            ")->fetchColumn();
            return ['payable' => round((float)$v, 2)];
        } catch (Throwable $e) { return ['payable' => 0.0]; }
    }
}

if (!function_exists('refundsPayablePosition')) {
    /**
     * Refunds Payable — refunds owed to customers that are not yet settled (a current
     * LIABILITY). Mirrors the income-statement de-dup so a refund is counted once:
     *   - unpaid credit notes (the credit note carries the refund), PLUS
     *   - approved sales returns with NO active credit note and not yet refunded.
     * @return array{payable: float}
     */
    function refundsPayablePosition(PDO $pdo): array {
        $total = 0.0;
        // Unpaid credit notes.
        if (rp_table_exists($pdo, 'credit_notes')) {
            try {
                $total += (float)$pdo->query("
                    SELECT COALESCE(SUM(grand_total), 0)
                      FROM credit_notes
                     WHERE status NOT IN ('paid','cancelled','rejected','deleted','draft')
                ")->fetchColumn();
            } catch (Throwable $e) { /* ignore */ }
        }
        // Approved sales returns awaiting a direct refund (no credit note carrying it).
        if (rp_table_exists($pdo, 'sales_returns')) {
            try {
                $hasCN = rp_table_exists($pdo, 'credit_notes');
                $notVia = $hasCN
                    ? "AND NOT EXISTS (SELECT 1 FROM credit_notes cn
                                        WHERE cn.sales_return_id = sr.sales_return_id
                                          AND cn.status NOT IN ('deleted','rejected','cancelled'))"
                    : "";
                $total += (float)$pdo->query("
                    SELECT COALESCE(SUM(sr.grand_total), 0)
                      FROM sales_returns sr
                     WHERE sr.status = 'approved'
                       AND (sr.payment_status IS NULL OR sr.payment_status <> 'paid')
                       $notVia
                ")->fetchColumn();
            } catch (Throwable $e) { /* ignore */ }
        }
        return ['payable' => round($total, 2)];
    }
}
