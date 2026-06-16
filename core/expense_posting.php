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

require_once __DIR__ . '/ledger_post.php';   // postLedgerEntry
require_once __DIR__ . '/gl_accounts.php';   // accruedExpensesAccountId

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
