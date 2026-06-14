<?php
/**
 * core/expense_posting.php
 * ------------------------
 * money.md OUT-1: recognise expenses on an ACCRUAL basis so the GL Profit & Loss
 * matches expenses to the period they are incurred, not the period they are paid.
 *
 * Lifecycle:
 *   Approved → Dr Expense / Cr Accrued Expenses            (postExpenseAccrual)
 *   Paid     → Dr Accrued Expenses / Cr Bank               (the settlement; handled
 *              in update_expense_status.php by pointing the existing postOutflow at
 *              the Accrued Expenses account instead of the expense account)
 *   Rejected from approved → reverse the accrual           (reverseExpenseAccrual)
 *
 * This puts the expense in the P&L at approval (true accrual) while keeping the
 * cash movement, bank register, WHT and payroll links of the existing paid flow
 * unchanged. Accrued Expenses (2-1500) is kept separate from Trade Creditors so it
 * never collides with the supplier-AP that GRN / supplier payments use.
 *
 * Design rules (match the other B-series posters):
 *   - Best-effort: NEVER throws — a status change must not fail over accounting.
 *   - Idempotent: accrual on (entity_type='expense_accrual', expense_id); its
 *     reversal on (entity_type='expense_accrual_void', expense_id).
 *   - Joins the caller's open transaction; never touches accounts.current_balance.
 */

require_once __DIR__ . '/ledger_post.php';   // postLedgerEntry
require_once __DIR__ . '/gl_accounts.php';   // accruedExpensesAccountId

if (!function_exists('expenseAccrualEntryId')) {
    /** The posted accrual entry id for an expense, or null. */
    function expenseAccrualEntryId(PDO $pdo, int $expenseId): ?int
    {
        $s = $pdo->prepare("SELECT entry_id FROM journal_entries
                             WHERE entity_type='expense_accrual' AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$expenseId]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }
}

if (!function_exists('expenseAccrualVoided')) {
    /** True if the expense's accrual has already been reversed. */
    function expenseAccrualVoided(PDO $pdo, int $expenseId): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM journal_entries
                             WHERE entity_type='expense_accrual_void' AND entity_id=? AND status='posted' LIMIT 1");
        $s->execute([$expenseId]);
        return (bool)$s->fetchColumn();
    }
}

if (!function_exists('expenseIsAccrued')) {
    /** True when the expense has a live (un-reversed) accrual the payment must settle. */
    function expenseIsAccrued(PDO $pdo, int $expenseId): bool
    {
        return expenseAccrualEntryId($pdo, $expenseId) !== null && !expenseAccrualVoided($pdo, $expenseId);
    }
}

if (!function_exists('postExpenseAccrual')) {
    /**
     * OUT-1 — recognise an approved expense: Dr Expense / Cr Accrued Expenses.
     * Never throws. Idempotent on (entity_type='expense_accrual', expense_id).
     *
     * @return array ['posted'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function postExpenseAccrual(
        PDO $pdo, int $expenseId, int $expenseAccountId, float $amount,
        string $date, ?int $projectId, int $userId, ?string $reference, ?string $description
    ): array {
        $out = ['posted' => false, 'reason' => ''];
        if ($expenseId <= 0 || $amount <= 0) { $out['reason'] = 'no_amount'; return $out; }

        if ($existing = expenseAccrualEntryId($pdo, $expenseId)) {
            $out['posted'] = true; $out['reason'] = 'already_posted'; $out['entry_id'] = $existing;
            return $out;
        }

        $accrued = accruedExpensesAccountId($pdo);
        if ($expenseAccountId <= 0 || !$accrued) { $out['reason'] = 'accounts_not_configured'; return $out; }

        $amount = round($amount, 2);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$date) ? substr((string)$date, 0, 10) : date('Y-m-d');
        $pid  = ($projectId !== null && $projectId !== 0) ? (int)$projectId : null;
        $desc = 'Expense accrual ' . ($reference ?: ('#' . $expenseId)) . ($description ? ' — ' . substr($description, 0, 80) : '');

        try {
            $entry = postLedgerEntry($pdo, $desc, [
                ['account_id' => (int)$expenseAccountId, 'type' => 'debit',  'amount' => $amount, 'description' => 'Expense incurred'],
                ['account_id' => (int)$accrued,          'type' => 'credit', 'amount' => $amount, 'description' => 'Accrued (unpaid) expense'],
            ], $pid, $expenseId, 'expense_accrual', $date, $userId);
            $out['posted'] = true; $out['reason'] = 'posted'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("postExpenseAccrual failed (expense $expenseId): " . $e->getMessage());
            $out['reason'] = 'post_error';
        }
        return $out;
    }
}

if (!function_exists('reverseExpenseAccrual')) {
    /**
     * Reverse an expense accrual (e.g. an approved expense is rejected before
     * payment): posts the contra of the accrual entry — Dr Accrued / Cr Expense.
     * Never throws. Idempotent on (entity_type='expense_accrual_void', expense_id).
     *
     * @return array ['reversed'=>bool,'reason'=>string,'entry_id'?=>int]
     */
    function reverseExpenseAccrual(PDO $pdo, int $expenseId, int $userId): array
    {
        $out = ['reversed' => false, 'reason' => ''];
        $accrualId = expenseAccrualEntryId($pdo, $expenseId);
        if (!$accrualId) { $out['reason'] = 'no_accrual'; return $out; }
        if (expenseAccrualVoided($pdo, $expenseId)) { $out['reversed'] = true; $out['reason'] = 'already_reversed'; return $out; }

        // Flip the accrual's own lines so the contra is an exact mirror.
        $rows = $pdo->query("SELECT account_id, type, amount FROM journal_entry_items WHERE entry_id = " . (int)$accrualId)->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { $out['reason'] = 'no_lines'; return $out; }
        $hdr = $pdo->query("SELECT entry_date, project_id FROM journal_entries WHERE entry_id = " . (int)$accrualId)->fetch(PDO::FETCH_ASSOC);
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = [
                'account_id' => (int)$r['account_id'],
                'type'       => $r['type'] === 'debit' ? 'credit' : 'debit',
                'amount'     => (float)$r['amount'],
                'description'=> 'Accrual reversal',
            ];
        }
        $date = $hdr['entry_date'] ?: date('Y-m-d');
        $pid  = isset($hdr['project_id']) && $hdr['project_id'] !== null ? (int)$hdr['project_id'] : null;
        try {
            $entry = postLedgerEntry($pdo, "Expense accrual reversed — #$expenseId", $lines, $pid, $expenseId, 'expense_accrual_void', $date, $userId);
            $out['reversed'] = true; $out['reason'] = 'reversed'; $out['entry_id'] = $entry;
        } catch (Throwable $e) {
            error_log("reverseExpenseAccrual failed (expense $expenseId): " . $e->getMessage());
            $out['reason'] = 'reverse_error';
        }
        return $out;
    }
}
