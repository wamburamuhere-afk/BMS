<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
require_once __DIR__ . '/../../core/auto_post_hook.php';
require_once __DIR__ . '/../../core/payment_source.php';   // postOutflow / reverseOutflow
require_once __DIR__ . '/../../core/bank_register.php';    // recordBankTransaction / reverse
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
                                       payroll_id, reference_number
                                  FROM expenses WHERE expense_id = ?");
    $snap_stmt->execute([$expense_id]);
    $expense_snap = $snap_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$expense_snap) throw new Exception('Expense not found');
    $old_status = $expense_snap['old_status'] ?? null;

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

    if ($status === 'paid') {
        if (!empty($expense_snap['transaction_id'])) {
            // Already posted (e.g. a legacy expense posted at create) — never double-post.
            $post_result = ['posted' => false, 'reason' => 'already_posted',
                            'existing_entry_id' => (int)$expense_snap['transaction_id']];
        } elseif ($amt > 0) {
            if ($bank <= 0 || $exp_acc <= 0) {
                throw new Exception('Cannot mark paid: this expense is missing its Paid-From account or expense account.');
            }
            $txnId = postOutflow($pdo, 'expense', $bank, $exp_acc, $amt,
                $expense_snap['expense_date'], $ref, $desc, $proj);
            if (!$txnId) {
                throw new Exception('Ledger posting failed — check the Paid-From and expense accounts.');
            }
            $pdo->prepare("UPDATE expenses SET transaction_id = ? WHERE expense_id = ?")
                ->execute([$txnId, $expense_id]);
            recordBankTransaction($pdo, $bank, $amt, 'withdrawal',
                $expense_snap['expense_date'], $ref, $desc, (int)$_SESSION['user_id']);
            if (!empty($expense_snap['payroll_id'])) {
                $pdo->prepare("UPDATE payroll SET payment_status = 'paid', payment_date = CURDATE()
                                WHERE payroll_id = ? AND status = 'approved'")
                    ->execute([(int)$expense_snap['payroll_id']]);
            }
            $post_result = ['posted' => true, 'entry_id' => $txnId];
        }
    } elseif ($status === 'rejected' && $old_status === 'paid' && !empty($expense_snap['transaction_id'])) {
        // VOID a posted expense: reverse the ledger + bank register, restore the
        // payroll, and unlink the transaction so the record can be re-posted later.
        reverseOutflow($pdo, (int)$expense_snap['transaction_id']);
        reverseBankTransaction($pdo, $bank, $ref, 'withdrawal');
        if (!empty($expense_snap['payroll_id'])) {
            $pdo->prepare("UPDATE payroll SET payment_status = 'approved', payment_date = NULL
                            WHERE payroll_id = ? AND status = 'approved'")
                ->execute([(int)$expense_snap['payroll_id']]);
        }
        $pdo->prepare("UPDATE expenses SET transaction_id = NULL WHERE expense_id = ?")->execute([$expense_id]);
        $post_result = ['posted' => false, 'reason' => 'voided'];
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
