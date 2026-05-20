<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
global $pdo;

header('Content-Type: application/json');

try {
    // Check if user is authenticated
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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

    // Fetch old payroll_id before update (needed to revert if changed/cleared)
    $oldPayrollRow = $pdo->prepare("SELECT payroll_id FROM expenses WHERE expense_id = ?");
    $oldPayrollRow->execute([$expense_id]);
    $old_payroll_id = (int)($oldPayrollRow->fetchColumn() ?? 0);

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

        // Fetch current transaction_id to update it
        $getTxn = $pdo->prepare("SELECT transaction_id FROM expenses WHERE expense_id = ?");
        $getTxn->execute([$expense_id]);
        $transactionId = $getTxn->fetchColumn();

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

        if ($transactionId) {
            $txnRes = updateGlobalTransaction($transactionId, $transactionData, $pdo);
            if (!$txnRes['success']) {
                throw new Exception("Transaction Update Failed: " . $txnRes['error']);
            }
        } else {
            // If somehow wasn't linked before, link it now
            $txnResult = recordGlobalTransaction($transactionData, $pdo);
            if ($txnResult['success']) {
                $pdo->prepare("UPDATE expenses SET transaction_id = ? WHERE expense_id = ?")
                    ->execute([$txnResult['transaction_id'], $expense_id]);
            } else {
                throw new Exception("Transaction Recording Failed: " . $txnResult['error']);
            }
        }

        // Sync payroll payment_status
        if ($payroll_id && $payroll_id !== $old_payroll_id) {
            // New payroll linked → mark as paid
            $pdo->prepare("UPDATE payroll SET payment_status = 'paid', payment_date = CURDATE() WHERE payroll_id = ? AND status = 'approved'")
                ->execute([$payroll_id]);
        }
        if ($old_payroll_id && $old_payroll_id !== $payroll_id) {
            // Previously linked payroll removed/changed → revert to approved
            $pdo->prepare("UPDATE payroll SET payment_status = 'approved', payment_date = NULL WHERE payroll_id = ? AND status = 'approved'")
                ->execute([$old_payroll_id]);
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
