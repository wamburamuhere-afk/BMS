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

require_once __DIR__ . '/ledger_post.php';     // postLedgerEntry
require_once __DIR__ . '/vat.php';             // outputVatAccountId
require_once __DIR__ . '/gl_accounts.php';     // arAccountId, salesRevenueAccountId, cogsAccountId, inventoryAccountId (B0 — single home)
require_once __DIR__ . '/expense_posting.php'; // reverseAccrualEntry (shared reversal, for invoice-COGS)

// NOTE: arAccountId() and salesRevenueAccountId() now live in core/gl_accounts.php
// (the shared resolver library) so every money flow resolves control accounts the
// same way. They are included above; this file just uses them.

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

        // Mutual exclusion with the IPC path (money.md OUT-15): if this invoice was
        // generated from an interim payment certificate that already recognised its
        // contract revenue (entity_type='ipc'), the invoice is a billing/collection
        // document — recognising it again would double-count the same revenue.
        try {
            $ipcChk = $pdo->prepare("
                SELECT je.entry_id
                  FROM interim_payment_certificates ipc
                  JOIN journal_entries je ON je.entity_type='ipc' AND je.entity_id=ipc.ipc_id AND je.status='posted'
                 WHERE ipc.invoice_id = ? LIMIT 1");
            $ipcChk->execute([$invoiceId]);
            if ($ipcEntry = $ipcChk->fetchColumn()) {
                return ['posted' => false, 'reason' => 'recognised_via_ipc', 'existing_entry_id' => (int)$ipcEntry];
            }
        } catch (Throwable $e) { /* IPC table absent — no exclusion needed */ }

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

/* ── Invoice COGS (IS Phase 2 — matching principle) ─────────────────────────── */

if (!function_exists('invoiceCogsValue')) {
    /** Σ(invoice_items.quantity × products.cost_price) for the invoice's PRODUCT lines. */
    function invoiceCogsValue(PDO $pdo, int $invoiceId): float
    {
        $v = $pdo->query("SELECT COALESCE(SUM(ii.quantity * COALESCE(p.cost_price, 0)), 0)
                            FROM invoice_items ii
                            JOIN products p ON p.product_id = ii.product_id
                           WHERE ii.invoice_id = " . (int)$invoiceId . " AND ii.product_id IS NOT NULL")->fetchColumn();
        return round((float)$v, 2);
    }
}

if (!function_exists('_rp_already_posted')) {
    function _rp_already_posted(PDO $pdo, string $entityType, int $entityId): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type=? AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$entityType, $entityId]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('postInvoiceCOGS')) {
    /**
     * IS Phase 2 — recognise the cost of goods sold when an invoice is approved, so
     * the cost is matched to the revenue in the same period:
     *     Dr Cost of Goods Sold  /  Cr Inventory   = Σ(quantity × products.cost_price)
     * for the invoice's product lines (services have no product cost and post nothing).
     *
     * Best-effort (never blocks approval). Idempotent on (entity_type='invoice_cogs',
     * invoice_id). Skips POS-sourced invoices whose COGS the POS sale already posted
     * (entity 'pos_cogs'), so a POS sale converted to an invoice is never double-costed.
     *
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function postInvoiceCOGS(PDO $pdo, int $invoiceId, int $userId): array
    {
        $out = ['posted' => false, 'reason' => ''];
        if ($invoiceId <= 0) { $out['reason'] = 'invalid_invoice'; return $out; }

        if ($existing = _rp_already_posted($pdo, 'invoice_cogs', $invoiceId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        // Mutual exclusion with the POS path: a POS sale converted to this invoice
        // already posted its COGS (entity 'pos_cogs') — don't double-cost it.
        try {
            $posChk = $pdo->prepare("
                SELECT je.entry_id
                  FROM pos_sales ps
                  JOIN journal_entries je ON je.entity_type='pos_cogs' AND je.entity_id=ps.sale_id AND je.status='posted'
                 WHERE ps.invoice_id = ? LIMIT 1");
            $posChk->execute([$invoiceId]);
            if ($posChk->fetchColumn()) { $out['reason'] = 'recognised_via_pos'; return $out; }
        } catch (Throwable $e) { /* pos_sales absent — no exclusion needed */ }

        $cogs = invoiceCogsValue($pdo, $invoiceId);
        if ($cogs <= 0.01) { $out['reason'] = 'no_cogs'; return $out; }   // no product lines / zero cost (e.g. service/IPC invoice)

        $cogsAcc = cogsAccountId($pdo);
        $invAcc  = inventoryAccountId($pdo);
        if (!$cogsAcc || !$invAcc) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $r = $pdo->prepare("SELECT invoice_number, invoice_date, project_id FROM invoices WHERE invoice_id = ?");
        $r->execute([$invoiceId]);
        $inv = $r->fetch(PDO::FETCH_ASSOC);
        if (!$inv) { $out['reason'] = 'invoice_not_found'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$inv['invoice_date']) ? substr((string)$inv['invoice_date'], 0, 10) : date('Y-m-d');
        $pid  = !empty($inv['project_id']) ? (int)$inv['project_id'] : null;
        $desc = 'COGS for Invoice ' . ($inv['invoice_number'] ?: ('#' . $invoiceId));
        try {
            $entry = postLedgerEntry($pdo, $desc, [
                ['account_id' => (int)$cogsAcc, 'type' => 'debit',  'amount' => $cogs, 'description' => 'Cost of goods sold'],
                ['account_id' => (int)$invAcc,  'type' => 'credit', 'amount' => $cogs, 'description' => 'Inventory reduction'],
            ], $pid, $invoiceId, 'invoice_cogs', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postInvoiceCOGS failed (invoice $invoiceId): " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reverseInvoiceCOGS')) {
    /** Reverse an invoice's COGS entry (invoice cancelled/voided after approval). */
    function reverseInvoiceCOGS(PDO $pdo, int $invoiceId, int $userId): array
    {
        return reverseAccrualEntry($pdo, 'invoice_cogs', $invoiceId, $userId);
    }
}
