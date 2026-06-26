<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';
require_once __DIR__ . '/../../core/payment_source.php';
require_once __DIR__ . '/../../core/expense_posting.php';   // expenseIsAccrued / reverseExpenseAccrual
global $pdo;

header('Content-Type: application/json');

try {
    // Check if user is authenticated
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!canDelete('expenses')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete expenses']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    if (empty($_POST['expense_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing expense ID']);
        exit;
    }

    $expense_id = intval($_POST['expense_id']);
    $user_id = getCurrentUserId();

    // Phase C — block deletes against expenses on projects not in user scope
    assertScopeForRecord('expenses', 'expense_id', $expense_id);

    // Fetch status + transaction_id + bank/amount before deleting.
    $getTxn = $pdo->prepare("SELECT transaction_id, bank_account_id, amount, status FROM expenses WHERE expense_id = ?");
    $getTxn->execute([$expense_id]);
    $exp = $getTxn->fetch(PDO::FETCH_ASSOC) ?: [];
    $transactionId = $exp['transaction_id'] ?? null;

    // GAP 1 — a PAID expense is a completed payment and is locked: it cannot be
    // deleted. Reverse it via a void (set status to 'rejected' on the view) first.
    if (($exp['status'] ?? null) === 'paid') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This expense is paid and locked. Void it first (set status to Rejected) before deleting.']);
        exit;
    }

    // Start transaction
    $pdo->beginTransaction();

    // An APPROVED-but-unpaid expense posted an accrual at approval
    // (Dr Expense / Cr Accrued Expenses) but has NO transaction_id (that is only
    // set at payment). Deleting it must unwind that accrual, or the Expense (P&L)
    // and Accrued Expenses (Balance Sheet) stay overstated with no source doc.
    // Idempotent (keyed on expense_accrual_void); no-op if it was never accrued.
    if (expenseIsAccrued($pdo, $expense_id)) {
        reverseExpenseAccrual($pdo, $expense_id, (int)$user_id);
    }

    // Reverse the ledger + cash + register ONLY for a legacy expense that was
    // posted at create (transaction_id set). A new, not-yet-paid expense never
    // posted, so there is nothing to reverse.
    if ($transactionId) {
        require_once __DIR__ . '/../../core/bank_register.php';
        $txnRes = deleteGlobalTransaction($transactionId, $pdo);
        if (!$txnRes['success']) {
            throw new Exception("Transaction Deletion Failed: " . $txnRes['error']);
        }
        if (!empty($exp['bank_account_id']) && (float)($exp['amount'] ?? 0) > 0) {
            applyAccountBalanceDelta($pdo, (int)$exp['bank_account_id'], 'debit', (float)$exp['amount']);
        }
        reverseBankTransaction($pdo, (int)($exp['bank_account_id'] ?? 0), 'EXP-' . $expense_id, 'withdrawal');
    }

    // Archive the expense to deleted_expenses table
    $archive_sql = "INSERT INTO deleted_expenses (
        expense_id, expense_date, expense_account_id, bank_account_id, 
        category_id, amount, description, reference_number, 
        payment_method, vendor, notes, status, transaction_id, 
        created_by, updated_by, created_at, updated_at
    ) SELECT 
        expense_id, expense_date, expense_account_id, bank_account_id, 
        category_id, amount, description, reference_number, 
        payment_method, vendor, notes, status, transaction_id, 
        created_by, updated_by, created_at, updated_at
    FROM expenses WHERE expense_id = ?";
    
    $archive_stmt = $pdo->prepare($archive_sql);
    $archive_stmt->execute([$expense_id]);

    // Delete from expenses table
    $delete_sql = "DELETE FROM expenses WHERE expense_id = ?";
    $delete_stmt = $pdo->prepare($delete_sql);
    $delete_result = $delete_stmt->execute([$expense_id]);

    if ($delete_result) {
        logActivity($pdo, $user_id, "Delete expense", "deleted expense with id " . $expense_id);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Expense deleted successfully']);
    } else {
        $pdo->rollBack();
        throw new Exception('Failed to delete expense');
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Database error in delete_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in delete_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
