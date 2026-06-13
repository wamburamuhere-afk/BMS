<?php
/**
 * core/revenue_posting.php
 * ------------------------
 * IN-3 (money.md): recognise sales revenue when an invoice is APPROVED, by posting
 * ONE balanced double-entry into the canonical ledger (journal_entries) via
 * postLedgerEntry — the single ledger the Balance Sheet / account balances read.
 *
 *     Dr Accounts Receivable   (grand_total)
 *       Cr Sales Revenue       (grand_total − tax_amount)   ← net of VAT, incl. discount
 *       Cr Output VAT Payable  (tax_amount)                 ← 18% VAT (Tanzania), if any
 *
 * Why grand_total − tax_amount for revenue: it always balances against the AR debit
 * and matches the Income Statement's revenue figure (SUM(grand_total − tax_amount)).
 *
 * Idempotent: keyed on a posted journal_entries row with entity_type='invoice' +
 * entity_id=invoice_id — so re-approving never double-posts, and both approval paths
 * (approve_invoice.php and update_invoice_status.php) can call it safely.
 *
 * It stamps invoices.output_vat_posted so the VAT-return report still sees the VAT,
 * but it does NOT touch accounts.current_balance (that single-sided legacy path is
 * superseded — the GL is the one source of truth).
 */

require_once __DIR__ . '/ledger_post.php';   // postLedgerEntry
require_once __DIR__ . '/vat.php';           // outputVatAccountId

if (!function_exists('_rp_account_active')) {
    function _rp_account_active(PDO $pdo, int $accountId): bool
    {
        if ($accountId <= 0) return false;
        $s = $pdo->prepare("SELECT 1 FROM accounts WHERE account_id = ? AND status = 'active'");
        $s->execute([$accountId]);
        return (bool)$s->fetchColumn();
    }
}

if (!function_exists('arAccountId')) {
    /** Accounts Receivable account: setting → payment_received mapping → AR sub-type → code 1-1200. */
    function arAccountId(PDO $pdo): ?int
    {
        $v = (int)($pdo->query("SELECT setting_value FROM system_settings
                                 WHERE setting_key = 'default_accounts_receivable_account_id'
                                   AND setting_value REGEXP '^[0-9]+$' LIMIT 1")->fetchColumn() ?: 0);
        if (_rp_account_active($pdo, $v)) return $v;

        $v = (int)($pdo->query("SELECT credit_account_id FROM journal_mappings
                                 WHERE event_type = 'payment_received' LIMIT 1")->fetchColumn() ?: 0);
        if (_rp_account_active($pdo, $v)) return $v;

        $v = (int)($pdo->query("SELECT a.account_id FROM accounts a
                                  JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
                                 WHERE st.code = 'accounts_receivable' AND a.status = 'active'
                                 ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
        if ($v) return $v;

        $v = (int)($pdo->query("SELECT account_id FROM accounts
                                 WHERE account_code = '1-1200' AND status = 'active' LIMIT 1")->fetchColumn() ?: 0);
        return $v ?: null;
    }
}

if (!function_exists('salesRevenueAccountId')) {
    /** Sales Revenue account: setting → invoice_approved mapping → code 4-1000 → first revenue LEAF. */
    function salesRevenueAccountId(PDO $pdo): ?int
    {
        $v = (int)($pdo->query("SELECT setting_value FROM system_settings
                                 WHERE setting_key = 'default_sales_revenue_account_id'
                                   AND setting_value REGEXP '^[0-9]+$' LIMIT 1")->fetchColumn() ?: 0);
        if (_rp_account_active($pdo, $v)) return $v;

        $v = (int)($pdo->query("SELECT credit_account_id FROM journal_mappings
                                 WHERE event_type = 'invoice_approved' LIMIT 1")->fetchColumn() ?: 0);
        if (_rp_account_active($pdo, $v)) return $v;

        $v = (int)($pdo->query("SELECT account_id FROM accounts
                                 WHERE account_code = '4-1000' AND status = 'active' LIMIT 1")->fetchColumn() ?: 0);
        if ($v) return $v;

        // First active revenue LEAF (never a group header).
        $v = (int)($pdo->query("SELECT a.account_id FROM accounts a
                                  JOIN account_types at ON a.account_type_id = at.type_id
                                 WHERE at.category = 'revenue' AND a.status = 'active'
                                   AND NOT EXISTS (SELECT 1 FROM accounts ch WHERE ch.parent_account_id = a.account_id)
                                 ORDER BY a.account_code LIMIT 1")->fetchColumn() ?: 0);
        return $v ?: null;
    }
}

if (!function_exists('postInvoiceRevenue')) {
    /**
     * Post the invoice-approval revenue entry. Joins the caller's open transaction.
     *
     * @return array ['posted'=>bool, 'reason'=>string, 'entry_id'?=>int, 'existing_entry_id'?=>int]
     */
    function postInvoiceRevenue(PDO $pdo, int $invoiceId, int $userId): array
    {
        if ($invoiceId <= 0) return ['posted' => false, 'reason' => 'invalid_invoice'];

        // Idempotency — already posted?
        $chk = $pdo->prepare("SELECT entry_id FROM journal_entries
                               WHERE entity_type = 'invoice' AND entity_id = ? AND status = 'posted' LIMIT 1");
        $chk->execute([$invoiceId]);
        if ($existing = $chk->fetchColumn()) {
            return ['posted' => false, 'reason' => 'already_posted', 'existing_entry_id' => (int)$existing];
        }

        $r = $pdo->prepare("SELECT invoice_number, invoice_date, subtotal, tax_amount, grand_total, project_id, output_vat_posted
                              FROM invoices WHERE invoice_id = ?");
        $r->execute([$invoiceId]);
        $inv = $r->fetch(PDO::FETCH_ASSOC);
        if (!$inv) return ['posted' => false, 'reason' => 'invoice_not_found'];

        $grand = round((float)$inv['grand_total'], 2);
        $tax   = round((float)$inv['tax_amount'], 2);
        if ($grand <= 0) return ['posted' => false, 'reason' => 'no_amount'];
        if ($tax < 0 || $tax > $grand) $tax = 0.0;     // defensive
        $revenue = round($grand - $tax, 2);

        $ar  = arAccountId($pdo);
        $rev = salesRevenueAccountId($pdo);
        if (!$ar || !$rev) {
            return ['posted' => false, 'reason' => 'accounts_not_configured'];
        }
        $vat = ($tax > 0) ? outputVatAccountId($pdo) : null;

        $desc  = "Invoice #" . ($inv['invoice_number'] ?? $invoiceId) . " approved — revenue recognised";
        $date  = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$inv['invoice_date']) ? substr((string)$inv['invoice_date'], 0, 10) : date('Y-m-d');
        $pid   = ($inv['project_id'] !== null && $inv['project_id'] !== '') ? (int)$inv['project_id'] : null;

        $lines = [['account_id' => $ar, 'type' => 'debit', 'amount' => $grand, 'description' => $desc]];
        if ($vat && $tax > 0) {
            $lines[] = ['account_id' => (int)$rev, 'type' => 'credit', 'amount' => $revenue, 'description' => 'Sales revenue (net of VAT)'];
            $lines[] = ['account_id' => (int)$vat, 'type' => 'credit', 'amount' => $tax,     'description' => 'Output VAT (18%)'];
        } else {
            // No VAT (or VAT account unavailable) → full amount is revenue.
            $lines[] = ['account_id' => (int)$rev, 'type' => 'credit', 'amount' => $grand, 'description' => 'Sales revenue'];
        }

        $entryId = postLedgerEntry($pdo, $desc, $lines, $pid, $invoiceId, 'invoice', $date, $userId);

        // Keep the VAT-return report's stamp in sync (no current_balance nudge — the GL is the truth).
        if ($tax > 0 && array_key_exists('output_vat_posted', $inv) && $inv['output_vat_posted'] === null) {
            $pdo->prepare("UPDATE invoices SET output_vat_posted = ? WHERE invoice_id = ?")->execute([$tax, $invoiceId]);
        }

        return ['posted' => true, 'reason' => 'posted', 'entry_id' => (int)$entryId];
    }
}
