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

    if (empty($_POST['expense_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing expense ID']);
        exit;
    }

    $expense_id = intval($_POST['expense_id']);
    $user_id = getCurrentUserId();

    // Start transaction
    $pdo->beginTransaction();

    // Fetch transaction_id before deleting
    $getTxn = $pdo->prepare("SELECT transaction_id FROM expenses WHERE expense_id = ?");
    $getTxn->execute([$expense_id]);
    $transactionId = $getTxn->fetchColumn();

    // Delete global transaction if linked
    if ($transactionId) {
        $txnRes = deleteGlobalTransaction($transactionId, $pdo);
        if (!$txnRes['success']) {
            throw new Exception("Transaction Deletion Failed: " . $txnRes['error']);
        }
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
        logActivity($pdo, $user_id, "Deleted expense ID: " . $expense_id);
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
