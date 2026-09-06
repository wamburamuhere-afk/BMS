<?php
/**
 * core/tender_fee.php — pay & post the tender participation fee to the GL.
 *
 * Pulled out of api/tender_workflow.php's RECORD_FEE/DELETE cases, same
 * $ownTxn convention as core/tender_award.php::awardTenderToProject(), so a
 * CLI test that wraps everything in one rolled-back transaction can compose
 * it, and so both call sites share one testable place (post_principle.md).
 *
 * A participation fee > 0 is a real cash outflow, so it's posted exactly like
 * a "pay now" Quick Expense (api/account/add_expense.php): accrue then settle,
 * Dr Expense (via Accrued Expenses) / Cr the chosen Paid-From bank account.
 * It lives under its own entity namespace ('tender_participation_fee') rather
 * than reusing 'expense_accrual', so it can never collide with a real
 * expenses.expense_id sharing the same integer as a tender_id.
 *
 * A fee of 0 (nothing payable) posts nothing — there is no cash event to
 * record — but the tender still moves to INVITATION as before.
 */

require_once __DIR__ . '/expense_posting.php'; // postAccrualEntry / reverseAccrualEntry / accruedExpensesAccountId
require_once __DIR__ . '/payment_source.php';  // postOutflow / reverseOutflow
require_once __DIR__ . '/bank_register.php';   // recordBankTransaction / reverseBankTransaction

if (!function_exists('payTenderParticipationFee')) {
    /**
     * @param array $feeData { fee_amount: float, bank_account_id?: int, expense_account_id?: int }
     * @return array { success: bool, message: string, transaction_id?: ?int, fee_amount?: float }
     */
    function payTenderParticipationFee(PDO $pdo, int $tenderId, int $userId, array $feeData): array
    {
        $feeAmount = round((float)($feeData['fee_amount'] ?? 0), 2);
        if ($feeAmount < 0) {
            return ['success' => false, 'message' => 'Fee amount cannot be negative.'];
        }

        // Idempotency guard — same convention as the AWARDED guard in
        // core/tender_award.php: a fee already paid+posted is locked; it
        // cannot be silently re-recorded/re-posted (post_principle.md q3/q4).
        $chk = $pdo->prepare("SELECT participation_fee_paid FROM tenders WHERE tender_id = ?");
        $chk->execute([$tenderId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'message' => 'Tender not found'];
        }
        if (!empty($row['participation_fee_paid'])) {
            return ['success' => false, 'message' => "This tender's participation fee has already been paid and posted to the books — it cannot be recorded again."];
        }

        $bankAccountId    = !empty($feeData['bank_account_id']) ? (int)$feeData['bank_account_id'] : null;
        $expenseAccountId = !empty($feeData['expense_account_id']) ? (int)$feeData['expense_account_id'] : null;

        // A real fee must name the account it actually left from — no posting
        // without a named Dr/Cr pair (post_principle.md q2). A zero fee has
        // nothing to pay, so no account is required.
        if ($feeAmount > 0 && !$bankAccountId) {
            return ['success' => false, 'message' => 'Please choose the account the participation fee is paid from (Paid From).'];
        }

        // Fallback expense account — same defensive pattern as add_expense.php.
        if ($feeAmount > 0 && !$expenseAccountId) {
            $stmtAcc = $pdo->query("
                SELECT a.account_id FROM accounts a
                JOIN account_types at ON a.account_type_id = at.type_id
                WHERE a.status = 'active' AND at.category IN ('expense','finance_cost')
                LIMIT 1
            ");
            $expenseAccountId = $stmtAcc->fetchColumn() ?: null;
            if (!$expenseAccountId) {
                return ['success' => false, 'message' => 'No active Expense Account found. Please create one in the Chart of Accounts first.'];
            }
        }

        $ownTxn = !$pdo->inTransaction();
        if ($ownTxn) $pdo->beginTransaction();
        try {
            $feeDate = date('Y-m-d');
            $txnId = null;

            if ($feeAmount > 0) {
                $ref  = 'TENDER-FEE-' . $tenderId;
                $desc = 'Tender participation fee #' . $tenderId;

                // Same accrual-then-settle sequence as add_expense.php's Quick
                // Expense (pay now) path, so P&L recognition always lands on
                // the fee date regardless of entry point.
                $accr = postAccrualEntry($pdo, 'tender_participation_fee', 'Tender Participation Fee',
                    $tenderId, $expenseAccountId, $feeAmount, $feeDate, null, $userId, $ref, $desc);

                $settleDebit = $expenseAccountId;
                if (!empty($accr['posted'])) {
                    $accruedAcc = accruedExpensesAccountId($pdo);
                    if ($accruedAcc) $settleDebit = (int)$accruedAcc;
                }

                $txnId = postOutflow($pdo, 'tender_participation_fee', $bankAccountId, $settleDebit,
                    $feeAmount, $feeDate, $ref, $desc, null, 0, null);

                if (!$txnId) {
                    throw new Exception('Ledger posting failed — verify the expense account and paid-from account are active.');
                }

                recordBankTransaction($pdo, $bankAccountId, $feeAmount, 'withdrawal', $feeDate, $ref, $desc, $userId);
            }

            $stmt = $pdo->prepare("UPDATE tenders SET
                    status = 'INVITATION',
                    participation_fee_amount = ?,
                    participation_fee_paid = ?,
                    participation_fee_paid_date = ?,
                    participation_fee_bank_account_id = ?,
                    participation_fee_expense_account_id = ?,
                    participation_fee_transaction_id = ?,
                    updated_at = NOW()
                WHERE tender_id = ?");
            $stmt->execute([
                $feeAmount,
                $feeAmount > 0 ? 1 : 0,
                $feeAmount > 0 ? $feeDate : null,
                $feeAmount > 0 ? $bankAccountId : null,
                $feeAmount > 0 ? $expenseAccountId : null,
                $txnId,
                $tenderId,
            ]);

            if ($ownTxn) $pdo->commit();

            return [
                'success' => true,
                'message' => $feeAmount > 0
                    ? 'Participation fee paid and posted to the books. Tender is now under INVITATION status.'
                    : 'No fee payable — recorded. Tender is now under INVITATION status.',
                'transaction_id' => $txnId,
                'fee_amount' => $feeAmount,
            ];
        } catch (Throwable $e) {
            if ($ownTxn) $pdo->rollBack();
            return ['success' => false, 'message' => 'Error recording fee: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('reverseTenderParticipationFee')) {
    /**
     * Undo a paid+posted participation fee (tender delete): reverses the
     * accrual, the outflow, and the bank register row so nothing is left
     * pointing at a tender that no longer exists (post_principle.md q6).
     * Safe / no-op if the fee was never paid. Does NOT manage its own
     * transaction — reverseAccrualEntry()/reverseOutflow() run plain
     * statements, so the caller's transaction covers them.
     */
    function reverseTenderParticipationFee(PDO $pdo, int $tenderId, int $userId): void
    {
        $stmt = $pdo->prepare("SELECT participation_fee_paid, participation_fee_transaction_id, participation_fee_bank_account_id FROM tenders WHERE tender_id = ?");
        $stmt->execute([$tenderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['participation_fee_paid'])) return;

        reverseAccrualEntry($pdo, 'tender_participation_fee', $tenderId, $userId);
        if (!empty($row['participation_fee_transaction_id'])) {
            reverseOutflow($pdo, (int)$row['participation_fee_transaction_id']);
            reverseBankTransaction($pdo, (int)$row['participation_fee_bank_account_id'], 'TENDER-FEE-' . $tenderId, 'withdrawal');
        }
    }
}
