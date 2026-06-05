<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
require_once __DIR__ . '/../../core/payment_source.php';
global $pdo;

header('Content-Type: application/json');

try {
    // Check if user is authenticated
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!canEdit('expenses')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit expenses']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Validate required fields
    if (empty($_POST['expense_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing expense ID']);
        exit;
    }

    $required_fields = ['expense_date', 'amount', 'description']; // Removed expense_account_id
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required field: ' . $field]);
            exit;
        }
    }

    // Sanitize and prepare data
    $expense_id         = intval($_POST['expense_id']);

    // Phase C — block updates against expenses on projects not in user scope,
    // and verify the incoming project_id (if any) is also in user scope.
    assertScopeForRecord('expenses', 'expense_id', $expense_id);
    if (!empty($_POST['project_id']) && !userCan('project', (int)$_POST['project_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
        exit;
    }

    $expense_date       = $_POST['expense_date'];
    $expense_account_id = !empty($_POST['expense_account_id']) ? intval($_POST['expense_account_id']) : null;

    // Fallback if missing
    if (!$expense_account_id) {
        $stmtAcc = $pdo->query("SELECT account_id FROM accounts WHERE status = 'active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%expense%') LIMIT 1");
        $expense_account_id = $stmtAcc->fetchColumn();
    }
    $type_id            = !empty($_POST['expense_type']) ? intval($_POST['expense_type']) : null;
    $category_id        = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $amount             = floatval($_POST['amount']);
    $bank_account_id    = !empty($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null;
    $project_id         = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;

    // Source account is mandatory so the balance re-sync below always has a target.
    if (!$bank_account_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please choose the account the expense is paid from (Paid From).']);
        exit;
    }
    $description        = trim($_POST['description']);
    $notes              = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $status             = !empty($_POST['status']) ? $_POST['status'] : 'pending';
    $budget_id          = !empty($_POST['budget_id']) ? intval($_POST['budget_id']) : null;
    $voucher_id         = !empty($_POST['voucher_id']) ? intval($_POST['voucher_id']) : null;
    $updated_by         = getCurrentUserId();
    $expense_items      = isset($_POST['expense_items']) ? $_POST['expense_items'] : null;

    // Paid To Logic — unified paid_to_id from form
    $paid_to_type = !empty($_POST['paid_to_type']) ? $_POST['paid_to_type'] : null;
    $paid_to_id   = !empty($_POST['paid_to_id']) ? intval($_POST['paid_to_id']) : null;
    $invoice_id   = !empty($_POST['invoice_id']) ? intval($_POST['invoice_id']) : null;
    $payroll_id   = !empty($_POST['payroll_id']) ? intval($_POST['payroll_id']) : null;

    // Fetch old payroll_id + old source account/amount before update
    // (payroll needed to revert if changed/cleared; bank/amount needed to
    //  re-sync the account balance so an edit doesn't drift the cash position).
    $oldRow = $pdo->prepare("SELECT payroll_id, bank_account_id, amount, status, transaction_id FROM expenses WHERE expense_id = ?");
    $oldRow->execute([$expense_id]);
    $old = $oldRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $old_payroll_id     = (int)($old['payroll_id'] ?? 0);
    $old_bank_account_id = !empty($old['bank_account_id']) ? (int)$old['bank_account_id'] : null;
    $old_amount          = (float)($old['amount'] ?? 0);
    $old_status          = $old['status'] ?? null;
    $existing_txn_id     = !empty($old['transaction_id']) ? (int)$old['transaction_id'] : null;

    // GAP 1 — a PAID expense is a completed payment and is locked. Corrections go
    // through a void (set status to 'rejected' on the view), not an edit.
    if ($old_status === 'paid') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This expense is paid and locked. Void it first (set status to Rejected) to make changes.']);
        exit;
    }

    // Start database transaction
    $pdo->beginTransaction();

    // Update database
    $sql = "UPDATE expenses SET
        expense_date        = ?,
        expense_account_id  = ?,
        type_id             = ?,
        amount              = ?,
        bank_account_id     = ?,
        project_id          = ?,
        budget_id           = ?,
        voucher_id          = ?,
        description         = ?,
        notes               = ?,
        status              = ?,
        updated_by          = ?,
        paid_to_type        = ?,
        paid_to_id          = ?,
        invoice_id          = ?,
        payroll_id          = ?,
        expense_items       = ?
        WHERE expense_id    = ?";

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $expense_date, $expense_account_id, $type_id, $amount, $bank_account_id,
        $project_id, $budget_id, $voucher_id, $description, $notes, $status, $updated_by,
        $paid_to_type, $paid_to_id, $invoice_id, $payroll_id, $expense_items, $expense_id
    ]);

    if ($result) {
        // Sync Category
        $pdo->prepare("DELETE FROM expense_category_map WHERE expense_id = ?")->execute([$expense_id]);
        if ($category_id) {
            $pdo->prepare("INSERT INTO expense_category_map (expense_id, category_id) VALUES (?, ?)")
                ->execute([$expense_id, $category_id]);
        }

        // GAP 1 — ledger/cash re-sync runs ONLY for an expense that was already
        // posted (transaction_id set: the legacy pre-GAP-1 "post at create" rows).
        // A new, not-yet-paid expense holds no ledger entry, so editing it just
        // updates the record; it will post when marked Paid. No transaction is
        // ever CREATED on edit.
        if ($existing_txn_id) {
            require_once __DIR__ . '/../../core/bank_register.php';

            $transactionData = [
                'expense_id'        => $expense_id,
                'transaction_date'  => $expense_date,
                'amount'            => $amount,
                'transaction_type'  => 'expense',
                'payment_method'    => 'Cash/Bank',
                'reference_number'  => null,
                'account_id'        => $expense_account_id,
                'contra_account_id' => $bank_account_id,
                'project_id'        => $project_id,
                'description'       => $description
            ];
            $txnRes = updateGlobalTransaction($existing_txn_id, $transactionData, $pdo);
            if (!$txnRes['success']) {
                throw new Exception("Transaction Update Failed: " . $txnRes['error']);
            }

            // Re-sync the cash/bank balance: undo the old outflow, apply the new one.
            if ($old_bank_account_id && $old_amount > 0) {
                applyAccountBalanceDelta($pdo, $old_bank_account_id, 'debit', $old_amount);
            }
            if ($bank_account_id && $amount > 0) {
                applyAccountBalanceDelta($pdo, (int)$bank_account_id, 'credit', (float)$amount);
            }

            // Keep the bank-statement register row in sync (remove old, write new).
            $regRef = 'EXP-' . $expense_id;
            if ($old_bank_account_id) reverseBankTransaction($pdo, $old_bank_account_id, $regRef, 'withdrawal');
            if ($bank_account_id && $amount > 0) {
                recordBankTransaction($pdo, (int)$bank_account_id, (float)$amount, 'withdrawal',
                    $expense_date, $regRef, 'Expense #' . $expense_id . ': ' . substr($description, 0, 100), $updated_by);
            }

            // Sync payroll payment_status (legacy posted rows only).
            if ($payroll_id && $payroll_id !== $old_payroll_id) {
                $pdo->prepare("UPDATE payroll SET payment_status = 'paid', payment_date = CURDATE() WHERE payroll_id = ? AND status = 'approved'")
                    ->execute([$payroll_id]);
            }
            if ($old_payroll_id && $old_payroll_id !== $payroll_id) {
                $pdo->prepare("UPDATE payroll SET payment_status = 'approved', payment_date = NULL WHERE payroll_id = ? AND status = 'approved'")
                    ->execute([$old_payroll_id]);
            }
        }

        logActivity($pdo, $updated_by, "Updated expense ID: " . $expense_id . " - " . $description);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Expense updated successfully']);
    } else {
        throw new Exception('Failed to update expense');
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Database error in update_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error in update_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
