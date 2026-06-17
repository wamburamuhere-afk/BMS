<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/auto_post_hook.php';
require_once __DIR__ . '/../../core/payment_source.php';   // postOutflow / reverseOutflow
require_once __DIR__ . '/../../core/bank_register.php';    // recordBankTransaction / reverse
require_once __DIR__ . '/../../core/expense_posting.php';  // postExpenseAccrual / reverseExpenseAccrual (OUT-1)
require_once __DIR__ . '/../../core/wht.php';              // whtPayableAccountId / whtRatePercent (OUT-WHT)
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!canEdit('expenses')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change expense status']);
    exit;
}

try {
    $expense_id = $_POST['expense_id'] ?? 0;
    $status = $_POST['status'] ?? '';

    if ($expense_id <= 0 || empty($status)) {
        throw new Exception('Missing required parameters');
    }

    // Phase C — block status changes against expenses on projects not in user scope
    assertScopeForRecord('expenses', 'expense_id', $expense_id);

    $allowed_statuses = ['pending', 'reviewed', 'approved', 'paid', 'rejected'];
    if (!in_array($status, $allowed_statuses)) {
        throw new Exception('Invalid status');
    }

    $actor       = workflowActorSnapshot();
    $extra_update = '';
    $action       = null;

    if ($status === 'reviewed') {
        $extra_update = ', reviewed_by = ' . intval($_SESSION['user_id']);
        $action       = 'reviewed';
    } elseif ($status === 'approved') {
        $extra_update = ', approved_by = ' . intval($_SESSION['user_id']);
        $action       = 'approved';
    }

    // Snapshot BEFORE the UPDATE: amount/date/project for posting, the source
    // bank + expense accounts to post against, the current transaction_id +
    // status (so we post once and can void a paid expense), plus payroll link.
    $snap_stmt = $pdo->prepare("SELECT amount, expense_date, project_id, description,
                                       status AS old_status, transaction_id,
                                       bank_account_id, expense_account_id,
                                       payroll_id, reference_number,
                                       invoice_id, paid_to_type
                                  FROM expenses WHERE expense_id = ?");
    $snap_stmt->execute([$expense_id]);
    $expense_snap = $snap_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense_snap) throw new Exception('Expense not found');
    $old_status  = $expense_snap['old_status'] ?? null;
    // The linked supplier / sub-contractor invoice id (0 = none)
    $linkedInvId = (int)($expense_snap['invoice_id'] ?? 0);

    // Wrap status change + signature + auto-post in one transaction so a
    // ledger-posting failure rolls back the status change too.
    $pdo->beginTransaction();

    $stmt   = $pdo->prepare("UPDATE expenses SET status = ?, updated_at = NOW(), updated_by = ? $extra_update WHERE expense_id = ?");
    $result = $stmt->execute([$status, $_SESSION['user_id'], $expense_id]);

    if (!$result) throw new Exception('Failed to update status');

    $sigResult = ['has_signature' => true];
    if ($action !== null) {
        $sigResult = workflowCaptureSignature($pdo, 'expense', $expense_id, $action,
            $_SESSION['user_id'], $actor['name'], $actor['role']);
    }

    // GAP 1 — the money moves only at the 'paid' transition: post the double
    // entry (Dr expense / Cr bank) via the canonical postOutflow(), write the
    // bank-statement register row (GAP 2), mark a linked payroll paid, and store
    // the transaction_id. Posting is done ONCE (skip if already posted).
    // The reverse path (paid -> rejected) voids all of it.
    $post_result = ['posted' => false, 'reason' => 'status_not_paid'];
    $amt = (float)$expense_snap['amount'];
    $ref = $expense_snap['reference_number'] ?: ('EXP-' . $expense_id);
    $desc = 'Expense #' . $expense_id . ': ' . substr((string)$expense_snap['description'], 0, 100);
    $bank = (int)($expense_snap['bank_account_id'] ?? 0);
    $exp_acc = (int)($expense_snap['expense_account_id'] ?? 0);
    $proj = $expense_snap['project_id'] !== null ? (int)$expense_snap['project_id'] : null;

    // OUT-1 accrual — recognise the expense in the P&L when it is APPROVED
    // (Dr Expense / Cr Accrued Expenses), so the GL is accrual basis. Best-effort;
    // idempotent. The 'paid' step below then settles this accrual instead of
    // re-expensing. Skipped for payroll-linked expenses (payroll accrues its own
    // gross via postPayrollAccrual to avoid a double charge).
    if ($status === 'approved' && $amt > 0 && $exp_acc > 0 && empty($expense_snap['payroll_id'])) {
        $accr = postExpenseAccrual($pdo, (int)$expense_id, $exp_acc, $amt,
            $expense_snap['expense_date'], $proj, (int)$_SESSION['user_id'], $ref, $expense_snap['description']);
        if (!empty($accr['posted'])) $post_result = ['posted' => true, 'entry_id' => $accr['entry_id'], 'reason' => 'accrued'];
    }

    if ($status === 'paid') {
        if (!empty($expense_snap['transaction_id'])) {
            // Already posted (e.g. a legacy expense posted at create) — never double-post.
            $post_result = ['posted' => false, 'reason' => 'already_posted',
                            'existing_entry_id' => (int)$expense_snap['transaction_id']];
        } elseif ($amt > 0) {
            if ($bank <= 0 || $exp_acc <= 0) {
                throw new Exception('Cannot mark paid: this expense is missing its Paid-From account or expense account.');
            }

            $linkedPayrollId = !empty($expense_snap['payroll_id']) ? (int)$expense_snap['payroll_id'] : 0;

            if ($linkedPayrollId > 0) {
                // ── PAYROLL-LINKED PATH ─────────────────────────────────────────────
                // Correct settlement: Dr Salaries Payable / Cr Bank (partial or full).
                // This clears the liability that postPayrollAccrual raised at approval
                // and avoids the double-charge on Salaries Expense.
                // PAYE/NSSF/SDL are untouched — they were booked at payroll approval.
                $prStmt = $pdo->prepare("SELECT payroll_id, payroll_number, net_salary,
                                                amount_paid, payroll_date, accrual_transaction_id
                                           FROM payroll WHERE payroll_id = ?");
                $prStmt->execute([$linkedPayrollId]);
                $payrollRow = $prStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payrollRow) throw new Exception('Linked payroll record not found.');

                $payrollRemaining = round((float)$payrollRow['net_salary'] - (float)$payrollRow['amount_paid'], 2);
                if ($amt > $payrollRemaining + 0.005) {
                    throw new Exception(
                        'Payment amount (' . number_format($amt, 2) . ') exceeds remaining payroll balance ('
                        . number_format($payrollRemaining, 2) . ').'
                    );
                }

                $txnId = postPayrollPayment($pdo, $payrollRow, $bank, (int)$_SESSION['user_id'], $amt);
                if (!$txnId) {
                    throw new Exception('Payroll ledger posting failed — ensure Salaries Payable and Paid-From accounts are configured.');
                }
                $pdo->prepare("UPDATE expenses SET transaction_id = ? WHERE expense_id = ?")
                    ->execute([$txnId, $expense_id]);
                recordBankTransaction($pdo, $bank, $amt, 'withdrawal',
                    $expense_snap['expense_date'], $ref, $desc, (int)$_SESSION['user_id']);

                // Increment amount_paid; mark fully paid or partial.
                $newAmtPaid   = round((float)$payrollRow['amount_paid'] + $amt, 2);
                $newPayStatus = ($newAmtPaid >= (float)$payrollRow['net_salary'] - 0.005) ? 'paid' : 'partial';
                $pdo->prepare("UPDATE payroll SET amount_paid = ?, payment_status = ?, payment_date = CURDATE()
                                WHERE payroll_id = ?")
                    ->execute([$newAmtPaid, $newPayStatus, $linkedPayrollId]);

                $post_result = ['posted' => true, 'entry_id' => $txnId, 'wht_applied' => 0.0];

            } else {
                // ── REGULAR EXPENSE PATH (supplier, sub-contractor, or unlinked staff) ─
                // If accrued at approval, settle the accrual (Dr Accrued / Cr Bank).
                // Otherwise direct cash expense (Dr Expense / Cr Bank).
                $settle_debit = $exp_acc;
                if (expenseIsAccrued($pdo, (int)$expense_id)) {
                    $accruedAcc = accruedExpensesAccountId($pdo);
                    if ($accruedAcc) $settle_debit = (int)$accruedAcc;
                }

                // WHT: supplier/sub-contractor invoice with a WHT rate.
                // Entry: Dr expense (gross) / Cr Bank (net) / Cr WHT Payable (WHT).
                // VAT recognised at invoice approval — nothing extra here.
                $whtAmt        = 0.0;
                $whtAccId      = null;
                $invDataForPost = null;
                $newInvAmtPaid  = 0.0;
                $newInvStatus   = 'paid';
                if ($linkedInvId > 0 && in_array($expense_snap['paid_to_type'] ?? '', ['supplier', 'sub_contractor'], true)) {
                    $invStmt = $pdo->prepare("SELECT amount, amount_paid, wht_rate_id, wht_amount FROM supplier_invoices WHERE id = ?");
                    $invStmt->execute([$linkedInvId]);
                    $invDataForPost = $invStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$invDataForPost) throw new Exception('Linked invoice not found.');

                    $invTotal     = round((float)$invDataForPost['amount'], 2);
                    $invPaid      = round((float)$invDataForPost['amount_paid'], 2);
                    $invRemaining = round($invTotal - $invPaid, 2);
                    if ($amt > $invRemaining + 0.005) {
                        throw new Exception(
                            'Payment amount (' . number_format($amt, 2) . ') exceeds remaining invoice balance ('
                            . number_format($invRemaining, 2) . ').'
                        );
                    }
                    $newInvAmtPaid = round($invPaid + $amt, 2);
                    $newInvStatus  = ($newInvAmtPaid >= $invTotal - 0.005) ? 'paid' : 'partial';

                    // WHT applies only when this payment fully settles the invoice.
                    if ($newInvStatus === 'paid'
                            && (int)($invDataForPost['wht_rate_id'] ?? 0) > 0
                            && (float)($invDataForPost['wht_amount'] ?? 0) > 0) {
                        $resolvedWhtAcc = whtPayableAccountId($pdo);
                        if ($resolvedWhtAcc) {
                            $whtAmt   = round((float)$invDataForPost['wht_amount'], 2);
                            $whtAccId = $resolvedWhtAcc;
                        }
                    }
                }

                $txnId = postOutflow($pdo, 'expense', $bank, $settle_debit, $amt,
                    $expense_snap['expense_date'], $ref, $desc, $proj, $whtAmt, $whtAccId);
                if (!$txnId) {
                    throw new Exception('Ledger posting failed — check the Paid-From and expense accounts.');
                }
                $pdo->prepare("UPDATE expenses SET transaction_id = ? WHERE expense_id = ?")
                    ->execute([$txnId, $expense_id]);

                // Bank register: NET cash (gross minus any WHT withheld).
                $bankOut = ($whtAmt > 0) ? round($amt - $whtAmt, 2) : $amt;
                recordBankTransaction($pdo, $bank, $bankOut, 'withdrawal',
                    $expense_snap['expense_date'], $ref, $desc, (int)$_SESSION['user_id']);

                // Mark linked supplier/sub-contractor invoice paid or partial; stamp WHT on final payment.
                if ($linkedInvId > 0 && $invDataForPost !== null) {
                    $invUpdateSql = "UPDATE supplier_invoices
                                        SET status = ?,
                                            amount_paid = ?,
                                            payment_date = ?,
                                            payment_transaction_id = ?,
                                            payment_recorded_by = ?"
                                  . ($whtAmt > 0 ? ", wht_posted = ?" : "")
                                  . " WHERE id = ? AND status IN ('approved','partial')";
                    $invParams = $whtAmt > 0
                        ? [$newInvStatus, $newInvAmtPaid, $expense_snap['expense_date'], $txnId, (int)$_SESSION['user_id'], $whtAmt, $linkedInvId]
                        : [$newInvStatus, $newInvAmtPaid, $expense_snap['expense_date'], $txnId, (int)$_SESSION['user_id'], $linkedInvId];
                    $pdo->prepare($invUpdateSql)->execute($invParams);
                }

                $post_result = ['posted' => true, 'entry_id' => $txnId, 'wht_applied' => $whtAmt];
            }
        }
    } elseif ($status === 'rejected' && $old_status === 'paid' && !empty($expense_snap['transaction_id'])) {
        // VOID a posted expense: reverse the ledger + bank register, restore the
        // payroll / invoice, and unlink the transaction so the record can be re-posted.
        $voidPayrollId = !empty($expense_snap['payroll_id']) ? (int)$expense_snap['payroll_id'] : 0;

        if ($voidPayrollId > 0) {
            // Payroll payment was Dr Salaries Payable / Cr Bank — both legs must be
            // reversed (reverseOutflow only restores the credit/bank leg).
            reverseJournalBalances($pdo, (int)$expense_snap['transaction_id']);
        } else {
            reverseOutflow($pdo, (int)$expense_snap['transaction_id']);
        }
        reverseBankTransaction($pdo, $bank, $ref, 'withdrawal');

        if ($voidPayrollId > 0) {
            // Subtract the reversed amount from amount_paid; restore status.
            $pdo->prepare("UPDATE payroll
                              SET amount_paid    = GREATEST(0, ROUND(amount_paid - ?, 2)),
                                  payment_status = CASE
                                      WHEN GREATEST(0, ROUND(amount_paid - ?, 2)) <= 0 THEN 'approved'
                                      ELSE 'partial'
                                  END,
                                  payment_date   = CASE
                                      WHEN GREATEST(0, ROUND(amount_paid - ?, 2)) <= 0 THEN NULL
                                      ELSE payment_date
                                  END
                            WHERE payroll_id = ?")
                ->execute([$amt, $amt, $amt, $voidPayrollId]);
        }

        // Restore linked supplier/sub-contractor invoice: subtract voided amount from amount_paid.
        if ($linkedInvId > 0) {
            $invVoidStmt = $pdo->prepare("SELECT amount, amount_paid FROM supplier_invoices WHERE id = ?");
            $invVoidStmt->execute([$linkedInvId]);
            $invVoidRow = $invVoidStmt->fetch(PDO::FETCH_ASSOC);
            if ($invVoidRow) {
                $newVoidPaid   = max(0, round((float)$invVoidRow['amount_paid'] - $amt, 2));
                $newVoidStatus = ($newVoidPaid <= 0.005) ? 'approved' : 'partial';
                if ($newVoidPaid <= 0.005) {
                    $pdo->prepare("UPDATE supplier_invoices
                                      SET status = ?, amount_paid = 0,
                                          payment_date = NULL, payment_transaction_id = NULL, wht_posted = NULL
                                    WHERE id = ?")
                        ->execute([$newVoidStatus, $linkedInvId]);
                } else {
                    $pdo->prepare("UPDATE supplier_invoices SET status = ?, amount_paid = ? WHERE id = ?")
                        ->execute([$newVoidStatus, $newVoidPaid, $linkedInvId]);
                }
            }
        }
        $pdo->prepare("UPDATE expenses SET transaction_id = NULL WHERE expense_id = ?")->execute([$expense_id]);
        $post_result = ['posted' => false, 'reason' => 'voided'];
    } elseif ($status === 'rejected' && $old_status !== 'paid' && expenseIsAccrued($pdo, (int)$expense_id)) {
        // Rejected before payment — reverse the approval accrual (Dr Accrued / Cr Expense)
        // so the expense leaves the P&L. (A reject FROM paid keeps the accrual: the
        // expense was still incurred; only the payment is being undone above.)
        reverseExpenseAccrual($pdo, (int)$expense_id, (int)$_SESSION['user_id']);
        $post_result = ['posted' => false, 'reason' => 'accrual_reversed'];
    }

    // Phase 4 canonical-ledger hook (journal_entries via journal_mappings) — kept
    // wired but gated by is_active (quiet no-op by default). This is a SEPARATE
    // ledger from the transactions/books_transactions cash posting above and does
    // NOT move cash on its own; preserved here for the 'expense_paid' contract.
    if ($status === 'paid' && $amt > 0) {
        autoPostEvent(
            $pdo, 'expense_paid', 'expense', (int)$expense_id, $amt, $proj,
            $expense_snap['expense_date'], (int)$_SESSION['user_id'],
            "Expense #{$expense_id} paid: " . substr((string)$expense_snap['description'], 0, 100)
        );
    }

    $pdo->commit();

    $log_note = "Updated expense status to '$status' for expense ID: $expense_id";
    if (!empty($post_result['posted'])) {
        $log_note .= " (journal entry #{$post_result['entry_id']})";
    } elseif (($post_result['reason'] ?? '') === 'already_posted') {
        $log_note .= " (already in ledger as entry #{$post_result['existing_entry_id']})";
    }
    logActivity($pdo, $_SESSION['user_id'], $log_note);

    $response = ['success' => true, 'message' => 'Expense status updated successfully'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    if (!empty($post_result['posted'])) {
        $response['journal_entry_id'] = $post_result['entry_id'];
        if (!empty($post_result['wht_applied']) && $post_result['wht_applied'] > 0) {
            $response['wht_applied'] = $post_result['wht_applied'];
            $response['message'] .= ' WHT of ' . number_format($post_result['wht_applied'], 2) . ' withheld and posted to WHT Payable.';
        }
    } elseif (($post_result['reason'] ?? '') === 'mapping_not_configured') {
        $response['ledger_warning'] = "Expense marked paid, but no ledger entry was created — admin has not "
                                    . "set both Dr/Cr accounts for 'expense_paid' in Journal Mappings.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log("Error in update_expense_status.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
