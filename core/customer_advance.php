<?php
/**
 * core/customer_advance.php
 * -------------------------
 * money.md IN-7 — customer advances / deposits. A customer pays money BEFORE an
 * invoice exists; that cash is a LIABILITY (we owe goods/services) until applied to
 * an invoice. Modelled on WorkDo's "Retainer" pattern (Customer Deposits 2350),
 * fitted to BMS's existing receipt + payment_allocations + Client Deposits (2-1600).
 *
 * Double-entry (the WorkDo / IFRS treatment):
 *   Receive advance:  Dr Bank/Cash          / Cr Client Deposits (2-1600)   [liability ↑]
 *   Apply to invoice: Dr Client Deposits     / Cr Accounts Receivable (1-1200)
 *
 * Data model (reuses the generic payment_allocations table):
 *   - Advance receipt  = a `payments` row (invoice_id NULL) + an allocation row
 *                        (target_type='advance', target_id=customer_id, amount=deposit).
 *   - Advance applied  = an allocation row on that SAME advance payment
 *                        (target_type='invoice', target_id=invoice_id, amount=applied).
 *   - Available on a payment = its 'advance' amount − Σ its 'invoice' draw-downs.
 *   - Customer available     = Σ gross advances − Σ applied  (the Client Deposits
 *                              balance attributable to that customer).
 *
 * Design rules (match the other posting modules):
 *   - Post via postLedgerEntry into the ONE ledger (journal_entries); idempotent on
 *     (entity_type, entity_id); join the caller's open transaction; never touch
 *     accounts.current_balance; reversal = a contra entry (reverseAccrualEntry).
 */

require_once __DIR__ . '/ledger_post.php';      // postLedgerEntry
require_once __DIR__ . '/gl_accounts.php';      // clientDepositsAccountId, arAccountId, gl_account_active
require_once __DIR__ . '/expense_posting.php';  // reverseAccrualEntry (shared contra)

if (!function_exists('_ca_already_posted')) {
    function _ca_already_posted(PDO $pdo, string $entityType, int $entityId): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type=? AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$entityType, $entityId]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('postCustomerAdvanceReceipt')) {
    /**
     * Receive a customer advance: Dr Bank / Cr Client Deposits (2-1600).
     * Idempotent on (entity_type='customer_advance', entity_id=$paymentId). Never throws.
     *
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function postCustomerAdvanceReceipt(
        PDO $pdo, int $paymentId, int $bankAccountId, float $amount,
        string $date, ?string $reference, string $description, ?int $projectId, int $userId
    ): array {
        $out = ['posted' => false, 'reason' => ''];
        if ($paymentId <= 0) { $out['reason'] = 'invalid'; return $out; }
        $amount = round($amount, 2);
        if ($amount <= 0) { $out['reason'] = 'no_amount'; return $out; }

        if ($existing = _ca_already_posted($pdo, 'customer_advance', $paymentId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        if (!gl_account_active($pdo, $bankAccountId)) { $out['reason'] = 'bank_account_invalid'; return $out; }
        $dep = clientDepositsAccountId($pdo);
        if (!$dep) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;
        try {
            $entry = postLedgerEntry($pdo, $description, [
                ['account_id' => (int)$bankAccountId, 'type' => 'debit',  'amount' => $amount, 'description' => 'Customer advance received'],
                ['account_id' => (int)$dep,          'type' => 'credit', 'amount' => $amount, 'description' => 'Client deposit (advance held as liability)'],
            ], $pid, $paymentId, 'customer_advance', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postCustomerAdvanceReceipt payment $paymentId: " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reverseCustomerAdvanceReceipt')) {
    /** Reverse an advance receipt (the deposit was cancelled). Contra: Dr Client Deposits / Cr Bank. */
    function reverseCustomerAdvanceReceipt(PDO $pdo, int $paymentId, int $userId): array
    {
        return reverseAccrualEntry($pdo, 'customer_advance', $paymentId, $userId);
    }
}

if (!function_exists('postAdvanceApplication')) {
    /**
     * Apply (draw down) an advance against an invoice: Dr Client Deposits / Cr AR.
     * Keyed on the payment_allocations row id ($allocationId) so re-running is idempotent.
     * Never throws.
     *
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function postAdvanceApplication(
        PDO $pdo, int $allocationId, float $amount, string $date, ?int $projectId, int $userId, ?string $reference = null
    ): array {
        $out = ['posted' => false, 'reason' => ''];
        if ($allocationId <= 0) { $out['reason'] = 'invalid'; return $out; }
        $amount = round($amount, 2);
        if ($amount <= 0) { $out['reason'] = 'no_amount'; return $out; }

        if ($existing = _ca_already_posted($pdo, 'advance_application', $allocationId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $dep = clientDepositsAccountId($pdo);
        $ar  = arAccountId($pdo);
        if (!$dep || !$ar) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;
        $desc = 'Advance applied' . ($reference ? " ($reference)" : '');
        try {
            $entry = postLedgerEntry($pdo, $desc, [
                ['account_id' => (int)$dep, 'type' => 'debit',  'amount' => $amount, 'description' => 'Client deposit applied to invoice'],
                ['account_id' => (int)$ar,  'type' => 'credit', 'amount' => $amount, 'description' => 'Settles Accounts Receivable from deposit'],
            ], $pid, $allocationId, 'advance_application', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postAdvanceApplication allocation $allocationId: " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reverseAdvanceApplication')) {
    /** Reverse an advance application (un-apply). Contra: Dr AR / Cr Client Deposits. */
    function reverseAdvanceApplication(PDO $pdo, int $allocationId, int $userId): array
    {
        return reverseAccrualEntry($pdo, 'advance_application', $allocationId, $userId);
    }
}

/* ── Per-customer / per-payment advance balances (the deposit sub-ledger) ─────── */

if (!function_exists('customerAdvanceGross')) {
    /** Σ gross advances a customer has paid (the 'advance' marker allocations). */
    function customerAdvanceGross(PDO $pdo, int $customerId): float
    {
        $s = $pdo->prepare("
            SELECT COALESCE(SUM(pa.allocated_amount), 0)
              FROM payment_allocations pa
              JOIN payments p ON p.payment_id = pa.payment_id
             WHERE pa.target_type = 'advance' AND pa.target_id = ? AND p.status = 'completed'");
        $s->execute([$customerId]);
        return round((float)$s->fetchColumn(), 2);
    }
}

if (!function_exists('customerAdvanceApplied')) {
    /** Σ of a customer's advances already drawn down against invoices. */
    function customerAdvanceApplied(PDO $pdo, int $customerId): float
    {
        $s = $pdo->prepare("
            SELECT COALESCE(SUM(pa.allocated_amount), 0)
              FROM payment_allocations pa
             WHERE pa.target_type = 'invoice'
               AND pa.payment_id IN (
                   SELECT payment_id FROM payment_allocations
                    WHERE target_type = 'advance' AND target_id = ?)");
        $s->execute([$customerId]);
        return round((float)$s->fetchColumn(), 2);
    }
}

if (!function_exists('customerAdvanceAvailable')) {
    /** A customer's unused deposit = gross advances − applied. Never negative. */
    function customerAdvanceAvailable(PDO $pdo, int $customerId): float
    {
        return round(max(0.0, customerAdvanceGross($pdo, $customerId) - customerAdvanceApplied($pdo, $customerId)), 2);
    }
}

if (!function_exists('advancePaymentAvailable')) {
    /** Unused balance still sitting on ONE advance payment (gross − its draw-downs). */
    function advancePaymentAvailable(PDO $pdo, int $paymentId): float
    {
        $gross = (float)$pdo->query("SELECT COALESCE(SUM(allocated_amount),0) FROM payment_allocations
                                      WHERE payment_id = " . (int)$paymentId . " AND target_type='advance'")->fetchColumn();
        $appl  = (float)$pdo->query("SELECT COALESCE(SUM(allocated_amount),0) FROM payment_allocations
                                      WHERE payment_id = " . (int)$paymentId . " AND target_type='invoice'")->fetchColumn();
        return round($gross - $appl, 2);
    }
}
