<?php
/**
 * core/expense_posting.php
 * ------------------------
 * money.md OUT-1 / OUT-2: recognise expenses (and payment vouchers) on an ACCRUAL
 * basis so the GL Profit & Loss matches costs to the period they are incurred, not
 * the period they are paid.
 *
 * Lifecycle (per document):
 *   Approved → Dr Expense / Cr Accrued Expenses            (postExpenseAccrual / postVoucherAccrual)
 *   Paid     → Dr Accrued Expenses / Cr Bank               (the settlement; the endpoint points the
 *              existing postOutflow at the Accrued Expenses account instead of the expense account)
 *   Rejected/cancelled before payment → reverse the accrual
 *
 * The same engine serves expenses and vouchers via different entity bases
 * ('expense_accrual' / 'voucher_accrual'); the reversal uses '<base>_void'. Accrued
 * Expenses (2-1500) is kept separate from Trade Creditors so it never collides with
 * the supplier AP that GRN / supplier payments use.
 *
 * Design rules (match the other B-series posters): best-effort (never throws),
 * idempotent on the entity base, join the caller's transaction, never touch
 * accounts.current_balance.
 */

require_once __DIR__ . '/ledger_post.php';    // postLedgerEntry
require_once __DIR__ . '/gl_accounts.php';    // accruedExpensesAccountId
require_once __DIR__ . '/payment_source.php'; // reverseOutflowContra (voucher payment reversal)
require_once __DIR__ . '/bank_register.php';  // reverseBankTransaction (Maintenance wrappers below)

/* ── Generic accrual engine (parameterised by entity base) ──────────────────── */

if (!function_exists('accrualEntryId')) {
    /** The posted accrual entry id for (entityBase, id), or null. */
    function accrualEntryId(PDO $pdo, string $entityBase, int $id): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries WHERE entity_type=? AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$entityBase, $id]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('accrualVoided')) {
    /** True if the accrual for (entityBase, id) has already been reversed. */
    function accrualVoided(PDO $pdo, string $entityBase, int $id): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM journal_entries WHERE entity_type=? AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$entityBase . '_void', $id]);
        return (bool)$s->fetchColumn();
    }
}

if (!function_exists('isDocAccrued')) {
    /** True when the document has a live (un-reversed) accrual the payment must settle. */
    function isDocAccrued(PDO $pdo, string $entityBase, int $id): bool
    {
        return accrualEntryId($pdo, $entityBase, $id) !== null && !accrualVoided($pdo, $entityBase, $id);
    }
}

if (!function_exists('postAccrualEntry')) {
    /**
     * Recognise a cost: Dr Expense / Cr Accrued Expenses. Never throws. Idempotent
     * on (entityBase, id).
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function postAccrualEntry(
        PDO $pdo, string $entityBase, string $label, int $id, int $expenseAccountId, float $amount,
        string $date, ?int $projectId, int $userId, ?string $reference, ?string $description
    ): array {
        $out = ['posted' => false, 'reason' => ''];
        if ($id <= 0 || $amount <= 0) { $out['reason'] = 'no_amount'; return $out; }

        if ($existing = accrualEntryId($pdo, $entityBase, $id)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $accrued = accruedExpensesAccountId($pdo);
        if ($expenseAccountId <= 0 || !$accrued) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $amount = round($amount, 2);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;
        $desc = $label . ' accrual ' . ($reference ?: ('#' . $id)) . ($description ? ' — ' . substr($description, 0, 80) : '');

        try {
            $entry = postLedgerEntry($pdo, $desc, [
                ['account_id' => (int)$expenseAccountId, 'type' => 'debit',  'amount' => $amount, 'description' => $label . ' incurred'],
                ['account_id' => (int)$accrued,          'type' => 'credit', 'amount' => $amount, 'description' => 'Accrued (unpaid) ' . strtolower($label)],
            ], $pid, $id, $entityBase, $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postAccrualEntry $entityBase $id: " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reverseAccrualEntry')) {
    /**
     * Reverse an accrual (the document is rejected/cancelled before payment):
     * posts the exact mirror of the accrual — Dr Accrued / Cr Expense. Never throws.
     * Idempotent on (entityBase.'_void', id).
     * @return array ['reversed'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function reverseAccrualEntry(PDO $pdo, string $entityBase, int $id, int $userId): array
    {
        $out = ['reversed' => false, 'reason' => ''];
        $accrualId = accrualEntryId($pdo, $entityBase, $id);
        if (!$accrualId) { $out['reason'] = 'no_accrual'; return $out; }
        if (accrualVoided($pdo, $entityBase, $id)) { $out['reversed'] = true; $out['reason'] = 'already_reversed'; return $out; }

        $rows = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = " . (int)$accrualId)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { $out['reason'] = 'no_lines'; return $out; }
        $hdr = $pdo->query("SELECT entry_date, project_id FROM journal_entries WHERE entry_id = " . (int)$accrualId)->fetch(PDO::FETCH_ASSOC);
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = [
                'account_id'  => (int)$r['account_id'],
                'type'        => $r['type'] === 'debit' ? 'credit' : 'debit',
                'amount'      => (float)$r['amount'],
                'description' => 'Accrual reversal',
            ];
        }
        $date = $hdr['entry_date'] ?: date('Y-m-d');
        $pid  = isset($hdr['project_id']) && $hdr['project_id'] !== null ? (int)$hdr['project_id'] : null;
        try {
            $entry = postLedgerEntry($pdo, "Accrual reversed — $entityBase #$id", $lines, $pid, $id, $entityBase . '_void', $date, $userId);
            $out['reversed'] = true; $out['reason'] = 'reversed'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("reverseAccrualEntry $entityBase $id: " . $e->getMessage());
            $out['reason'] = 'reverse_error';
        }
        return $out;
    }
}

/* ── Expense wrappers (OUT-1) ──────────────────────────────────────────────── */

if (!function_exists('expenseIsAccrued')) {
    function expenseIsAccrued(PDO $pdo, int $expenseId): bool { return isDocAccrued($pdo, 'expense_accrual', $expenseId); }
}
if (!function_exists('postExpenseAccrual')) {
    function postExpenseAccrual(PDO $pdo, int $expenseId, int $expenseAccountId, float $amount, string $date, ?int $projectId, int $userId, ?string $reference, ?string $description): array {
        return postAccrualEntry($pdo, 'expense_accrual', 'Expense', $expenseId, $expenseAccountId, $amount, $date, $projectId, $userId, $reference, $description);
    }
}
if (!function_exists('reverseExpenseAccrual')) {
    function reverseExpenseAccrual(PDO $pdo, int $expenseId, int $userId): array { return reverseAccrualEntry($pdo, 'expense_accrual', $expenseId, $userId); }
}

/* ── Voucher wrappers (OUT-2) ──────────────────────────────────────────────── */

if (!function_exists('voucherIsAccrued')) {
    function voucherIsAccrued(PDO $pdo, int $voucherId): bool { return isDocAccrued($pdo, 'voucher_accrual', $voucherId); }
}
if (!function_exists('postVoucherAccrual')) {
    function postVoucherAccrual(PDO $pdo, int $voucherId, int $expenseAccountId, float $amount, string $date, ?int $projectId, int $userId, ?string $reference, ?string $description): array {
        return postAccrualEntry($pdo, 'voucher_accrual', 'Voucher', $voucherId, $expenseAccountId, $amount, $date, $projectId, $userId, $reference, $description);
    }
}
if (!function_exists('reverseVoucherAccrual')) {
    function reverseVoucherAccrual(PDO $pdo, int $voucherId, int $userId): array { return reverseAccrualEntry($pdo, 'voucher_accrual', $voucherId, $userId); }
}

if (!function_exists('reverseVoucherPayment')) {
    /**
     * Reverse ONE recorded voucher payment (Style B contra-entry) — for when a
     * payment was recorded by mistake (wrong amount/account, duplicate click). A
     * voucher can carry several partial payments, so this targets one specific
     * voucher_payments row, never the whole voucher.
     *
     * Undoes the GL leg via reverseOutflowContra() (leaves the original posted
     * entry untouched forever, matches assertJournalNotPosted's documented
     * principle), removes that payment's OWN bank-register row by its captured
     * id — never by reference match, since two partial payments on the same
     * voucher can share a reference — and recomputes the voucher's status from
     * its remaining non-reversed payments. Idempotent.
     *
     * @return array ['reversed'=>bool, 'reason'=>string, 'voucher_new_status'?=>string]
     */
    function reverseVoucherPayment(PDO $pdo, int $voucherPaymentId, int $userId): array
    {
        $out = ['reversed' => false, 'reason' => ''];
        if ($voucherPaymentId <= 0) { $out['reason'] = 'invalid_payment'; return $out; }

        $s = $pdo->prepare("SELECT * FROM voucher_payments WHERE id = ?");
        $s->execute([$voucherPaymentId]);
        $vp = $s->fetch(PDO::FETCH_ASSOC);
        if (!$vp) { $out['reason'] = 'not_found'; return $out; }

        if (!empty($vp['reversed_at'])) {
            $out['reversed'] = true; $out['reason'] = 'already_reversed'; return $out;
        }

        // 1. Reverse the GL leg (Dr Bank / Cr Accrued Expenses — the contra of the
        //    original Dr Accrued Expenses / Cr Bank settlement).
        $rev = ['reversed' => false, 'reason' => 'no_gl_transaction'];
        if (!empty($vp['gl_transaction_id'])) {
            $rev = reverseOutflowContra($pdo, (int)$vp['gl_transaction_id'], $userId);
        }
        if (empty($rev['reversed']) && ($rev['reason'] ?? '') !== 'already_reversed') {
            $out['reason'] = 'gl_reverse_failed:' . ($rev['reason'] ?? 'unknown');
            return $out;
        }

        // 2. Remove this payment's own bank-register row (precise id captured at
        //    record time — never a reference-based match).
        if (!empty($vp['bank_transaction_id'])) {
            $pdo->prepare("DELETE FROM bank_transactions WHERE transaction_id = ?")
                ->execute([(int)$vp['bank_transaction_id']]);
        }

        // 3. Mark this payment reversed — excluded from all future "already paid"
        //    sums (record_voucher_payment.php, delete_voucher.php's lock check).
        $pdo->prepare("UPDATE voucher_payments SET reversed_at = NOW(), reversed_by = ? WHERE id = ?")
            ->execute([$userId, $voucherPaymentId]);

        // 4. Recompute the voucher's status from its remaining non-reversed payments.
        $voucherId = (int)$vp['voucher_id'];
        $remaining = round((float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM voucher_payments
                                                 WHERE voucher_id = $voucherId AND reversed_at IS NULL")->fetchColumn(), 2);
        $total = round((float)$pdo->query("SELECT amount FROM payment_vouchers WHERE id = $voucherId")->fetchColumn(), 2);
        $newStatus = $remaining <= 0.005 ? 'approved' : ($remaining >= $total - 0.005 ? 'paid' : 'partially_paid');
        $pdo->prepare("UPDATE payment_vouchers SET status = ? WHERE id = ?")->execute([$newStatus, $voucherId]);

        $out['reversed'] = true; $out['reason'] = 'reversed'; $out['voucher_new_status'] = $newStatus;
        return $out;
    }
}

/* ── Employee Trip wrappers ──────────────────────────────────────────────────
 * Trips previously never moved money (D26): estimated_cost/requested_advance were
 * informational only, and expense_reference was a plain string pointing at a
 * separate petty-cash/expense record. This adds real GL posting on the same
 * accrual-then-settle lifecycle as expenses/vouchers, off the same generic engine:
 *   Approved → Dr Expense (employee_trips.expense_account_id) / Cr Accrued Expenses
 *   Paid     → Dr Accrued Expenses / Cr Bank (settlement; see postOutflow in
 *              api/manage_trip.php's 'pay' action)
 *   Cancelled/deleted at ANY later stage → reverse whatever was posted (both the
 *   settlement and the accrual, unconditionally — a cancelled trip must leave zero
 *   trace in the ledger, unlike an expense reject-from-paid which only undoes cash). */

if (!function_exists('tripIsAccrued')) {
    function tripIsAccrued(PDO $pdo, int $tripId): bool { return isDocAccrued($pdo, 'trip_accrual', $tripId); }
}
if (!function_exists('postTripAccrual')) {
    function postTripAccrual(PDO $pdo, int $tripId, int $expenseAccountId, float $amount, string $date, ?int $projectId, int $userId, ?string $reference, ?string $description): array {
        return postAccrualEntry($pdo, 'trip_accrual', 'Trip', $tripId, $expenseAccountId, $amount, $date, $projectId, $userId, $reference, $description);
    }
}
if (!function_exists('reverseTripAccrual')) {
    function reverseTripAccrual(PDO $pdo, int $tripId, int $userId): array { return reverseAccrualEntry($pdo, 'trip_accrual', $tripId, $userId); }
}

/* ── Maintenance Log wrappers ──────────────────────────────────────────────
 * post_principle.md gap fix (2026-07-30): maintenance_logs previously posted
 * the accrual (Dr Expense / Cr Accrued Expenses at status=completed) but had
 * no payment/settlement step anywhere in the module — the liability it
 * raised could never be system-settled. Same accrual-then-settle lifecycle
 * as Trips, off the same generic engine:
 *   Completed → Dr Expense (maintenance_logs.expense_account_id) / Cr Accrued Expenses
 *   Paid      → Dr Accrued Expenses / Cr Bank (settlement; see postOutflow in
 *               api/operations/save_maintenance_log.php's 'paid' status)
 *   Cancelled/deleted → reverse whatever was posted (settlement first, then
 *   the accrual) — shared by save_maintenance_log.php's 'cancelled' status
 *   and delete_maintenance_log.php's soft-delete, so a deleted log never
 *   leaves an orphaned journal entry behind (post_principle.md Q6). */

if (!function_exists('maintenanceLogIsAccrued')) {
    function maintenanceLogIsAccrued(PDO $pdo, int $logId): bool { return isDocAccrued($pdo, 'maintenance_log', $logId); }
}
if (!function_exists('reverseMaintenanceLedger')) {
    function reverseMaintenanceLedger(PDO $pdo, array $log, int $userId): void
    {
        $logId = (int)$log['log_id'];
        if (!empty($log['transaction_id'])) {
            reverseOutflow($pdo, (int)$log['transaction_id']);
            if (!empty($log['paid_from_account_id'])) {
                reverseBankTransaction($pdo, (int)$log['paid_from_account_id'], 'ML-' . $logId, 'withdrawal');
            }
        }
        if (accrualEntryId($pdo, 'maintenance_log', $logId) !== null && !accrualVoided($pdo, 'maintenance_log', $logId)) {
            reverseAccrualEntry($pdo, 'maintenance_log', $logId, $userId);
        }
    }
}
