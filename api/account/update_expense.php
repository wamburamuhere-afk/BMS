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

    $required_fields = ['expense_date', 'expense_account_id', 'amount', 'bank_account_id', 'description'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required field: ' . $field]);
            exit;
        }
    }

    // Sanitize and prepare data
    $expense_id = intval($_POST['expense_id']);
    $expense_date = $_POST['expense_date'];
    $expense_account_id = intval($_POST['expense_account_id']);
    $amount = floatval($_POST['amount']);
    $bank_account_id = intval($_POST['bank_account_id']);
    $project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : null;
    $description = trim($_POST['description']);
    $reference_number = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : null;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $vendor = isset($_POST['vendor']) ? trim($_POST['vendor']) : null;
    $status = isset($_POST['status']) ? $_POST['status'] : 'pending';
    $budget_id = !empty($_POST['budget_id']) ? intval($_POST['budget_id']) : null;
    $voucher_id = !empty($_POST['voucher_id']) ? intval($_POST['voucher_id']) : null;
    $updated_by = getCurrentUserId();
    
    // Paid To Logic
    $paid_to_type = !empty($_POST['paid_to_type']) ? $_POST['paid_to_type'] : null;
    $paid_to_id = null;
    if ($paid_to_type === 'supplier') {
        $paid_to_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
    } else if ($paid_to_type === 'staff') {
        $paid_to_id = !empty($_POST['staff_id']) ? intval($_POST['staff_id']) : null;
    }
    
    // Start database transaction
    $pdo->beginTransaction();

    // Update database
    $sql = "UPDATE expenses SET 
        expense_date = ?, 
        expense_account_id = ?, 
        amount = ?, 
        bank_account_id = ?, 
        project_id = ?,
        budget_id = ?,
        voucher_id = ?,
        description = ?, 
        reference_number = ?, 
        notes = ?, 
        vendor = ?, 
        status = ?, 
        updated_by = ?,
        paid_to_type = ?,
        paid_to_id = ?
        WHERE expense_id = ?";

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $expense_date, $expense_account_id, $amount, $bank_account_id, $project_id,
        $budget_id, $voucher_id, $description,
        $reference_number, $notes, $vendor, $status, $updated_by,
        $paid_to_type, $paid_to_id, $expense_id
    ]);

    if ($result) {
        // Fetch current transaction_id to update it
        $getTxn = $pdo->prepare("SELECT transaction_id FROM expenses WHERE expense_id = ?");
        $getTxn->execute([$expense_id]);
        $transactionId = $getTxn->fetchColumn();

        $transactionData = [
            'expense_id' => $expense_id,
            'transaction_date' => $expense_date,
            'amount' => $amount,
            'transaction_type' => 'expense',
            'payment_method' => 'Cash/Bank',
            'reference_number' => $reference_number,
            'account_id' => $expense_account_id,
            'contra_account_id' => $bank_account_id,
            'project_id' => $project_id,
            'description' => $description
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
