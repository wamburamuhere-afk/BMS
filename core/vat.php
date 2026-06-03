<?php
/**
 * core/vat.php
 * ------------
 * VAT 18% control-account helpers (accrual / invoice basis).
 *
 * Two control accounts hold the running VAT position (Sage/QuickBooks model):
 *
 *     Output VAT Payable    (liability)  ← VAT charged on SALES invoices
 *     Input VAT Recoverable (asset)      ← VAT paid on RECEIVED invoices
 *
 * VAT is recognised when an invoice is APPROVED (accrual basis), regardless of
 * payment. Posting moves only the control account's current_balance — it never
 * touches revenue/AP/AR (those stay where the documents already report them),
 * so the balance sheet can read a real, netted VAT position.
 *
 * Idempotent + exactly reversible: each document records the precise VAT amount
 * it posted (invoices.output_vat_posted / supplier_invoices.input_vat_posted);
 * NULL means "not posted". post* no-ops if already posted; reverse* no-ops if
 * not posted, and always reverses the stored amount (immune to later edits).
 *
 * Reuses applyAccountBalanceDelta() from payment_source.php — the same balance
 * mover used by every payment — so there is a single balance system, not two.
 */

require_once __DIR__ . '/payment_source.php';

if (!function_exists('outputVatAccountId')) {
    /** Configured Output VAT Payable account (liability), or null. */
    function outputVatAccountId(PDO $pdo): ?int
    {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_output_vat_account_id' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        return ($v !== false && (int)$v > 0) ? (int)$v : null;
    }
}

if (!function_exists('inputVatAccountId')) {
    /** Configured Input VAT Recoverable account (asset), or null. */
    function inputVatAccountId(PDO $pdo): ?int
    {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'default_input_vat_account_id' LIMIT 1");
        $s->execute();
        $v = $s->fetchColumn();
        return ($v !== false && (int)$v > 0) ? (int)$v : null;
    }
}

if (!function_exists('postOutputVat')) {
    /**
     * Recognise output VAT on a SALES invoice (called when it is approved).
     * Credits Output VAT Payable by the invoice's tax_amount (a liability ↑).
     * No-op if already posted, if the account is unconfigured, or if tax is 0.
     */
    function postOutputVat(PDO $pdo, int $invoiceId): void
    {
        $acc = outputVatAccountId($pdo);
        if (!$acc) return;
        $r = $pdo->prepare("SELECT tax_amount, output_vat_posted FROM invoices WHERE invoice_id = ?");
        $r->execute([$invoiceId]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['output_vat_posted'] !== null) return;   // gone or already posted
        $tax = round((float)$row['tax_amount'], 2);
        if ($tax <= 0) return;                                     // nothing to recognise
        applyAccountBalanceDelta($pdo, $acc, 'credit', $tax);       // liability ↑
        $pdo->prepare("UPDATE invoices SET output_vat_posted = ? WHERE invoice_id = ?")
            ->execute([$tax, $invoiceId]);
    }
}

if (!function_exists('reverseOutputVat')) {
    /**
     * Undo previously-recognised output VAT (invoice cancelled / deleted /
     * pushed back below approved). Debits Output VAT Payable by the exact
     * amount that was posted. No-op if nothing was posted.
     */
    function reverseOutputVat(PDO $pdo, int $invoiceId): void
    {
        $acc = outputVatAccountId($pdo);
        if (!$acc) return;
        $r = $pdo->prepare("SELECT output_vat_posted FROM invoices WHERE invoice_id = ?");
        $r->execute([$invoiceId]);
        $posted = $r->fetchColumn();
        if ($posted === null || $posted === false) return;         // nothing posted
        applyAccountBalanceDelta($pdo, $acc, 'debit', round((float)$posted, 2));  // liability ↓
        $pdo->prepare("UPDATE invoices SET output_vat_posted = NULL WHERE invoice_id = ?")
            ->execute([$invoiceId]);
    }
}

if (!function_exists('postInputVat')) {
    /**
     * Recognise input VAT on a RECEIVED invoice (called when it is approved).
     * Debits Input VAT Recoverable by the invoice's tax_amount (an asset ↑).
     * No-op if already posted, if the account is unconfigured, or if tax is 0.
     */
    function postInputVat(PDO $pdo, int $supplierInvoiceId): void
    {
        $acc = inputVatAccountId($pdo);
        if (!$acc) return;
        $r = $pdo->prepare("SELECT tax_amount, input_vat_posted FROM supplier_invoices WHERE id = ?");
        $r->execute([$supplierInvoiceId]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['input_vat_posted'] !== null) return;
        $tax = round((float)$row['tax_amount'], 2);
        if ($tax <= 0) return;
        applyAccountBalanceDelta($pdo, $acc, 'debit', $tax);        // asset ↑
        $pdo->prepare("UPDATE supplier_invoices SET input_vat_posted = ? WHERE id = ?")
            ->execute([$tax, $supplierInvoiceId]);
    }
}

if (!function_exists('reverseInputVat')) {
    /**
     * Undo previously-recognised input VAT (received invoice deleted / pushed
     * back below approved). Credits Input VAT Recoverable by the exact amount
     * posted. No-op if nothing was posted.
     */
    function reverseInputVat(PDO $pdo, int $supplierInvoiceId): void
    {
        $acc = inputVatAccountId($pdo);
        if (!$acc) return;
        $r = $pdo->prepare("SELECT input_vat_posted FROM supplier_invoices WHERE id = ?");
        $r->execute([$supplierInvoiceId]);
        $posted = $r->fetchColumn();
        if ($posted === null || $posted === false) return;
        applyAccountBalanceDelta($pdo, $acc, 'credit', round((float)$posted, 2));  // asset ↓
        $pdo->prepare("UPDATE supplier_invoices SET input_vat_posted = NULL WHERE id = ?")
            ->execute([$supplierInvoiceId]);
    }
}

if (!function_exists('vatNetPosition')) {
    /**
     * Current net VAT position from the two control-account balances.
     * @return array{output:float,input:float,net:float,label:string}
     *   net > 0 → 'payable' (owe TRA); net < 0 → 'refundable' (TRA owes you).
     */
    function vatNetPosition(PDO $pdo): array
    {
        $bal = function (?int $id) use ($pdo): float {
            if (!$id) return 0.0;
            $s = $pdo->prepare("SELECT COALESCE(current_balance,0) FROM accounts WHERE account_id = ?");
            $s->execute([$id]);
            return (float)$s->fetchColumn();
        };
        $out = $bal(outputVatAccountId($pdo));
        $in  = $bal(inputVatAccountId($pdo));
        $net = round($out - $in, 2);
        return [
            'output' => $out,
            'input'  => $in,
            'net'    => $net,
            'label'  => $net >= 0 ? 'payable' : 'refundable',
        ];
    }
}
