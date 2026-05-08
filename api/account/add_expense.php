<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../helpers/transaction_helper.php';

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
    $required_fields = ['expense_date', 'expense_account_id', 'amount', 'bank_account_id', 'description'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required field: ' . $field]);
            exit;
        }
    }

    // Sanitize and prepare data
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
    $created_by = getCurrentUserId();
    
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

    // Insert into database
    $sql = "INSERT INTO expenses (
        expense_date, expense_account_id, amount, bank_account_id, project_id, budget_id, voucher_id, description, 
        reference_number, notes, vendor, status, created_by, paid_to_type, paid_to_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $expense_date, $expense_account_id, $amount, $bank_account_id, $project_id, $budget_id, $voucher_id, $description,
        $reference_number, $notes, $vendor, $status, $created_by, $paid_to_type, $paid_to_id
    ]);

    if ($result) {
        $expense_id = $pdo->lastInsertId();
        
        // Record Global Transaction and link it
        $transactionData = [
            'expense_id' => $expense_id,
            'transaction_date' => $expense_date,
            'amount' => $amount,
            'transaction_type' => 'expense',
            'payment_method' => 'Cash/Bank', // Defaulting for now, could be dynamic
            'reference_number' => $reference_number,
            'account_id' => $expense_account_id,
            'contra_account_id' => $bank_account_id,
            'project_id' => $project_id,
            'description' => $description
        ];

        $txnResult = recordGlobalTransaction($transactionData, $pdo);
        
        if ($txnResult['success']) {
            // Update expense with transaction_id for future sync/deletion
            $updateSql = "UPDATE expenses SET transaction_id = ? WHERE expense_id = ?";
            $pdo->prepare($updateSql)->execute([$txnResult['transaction_id'], $expense_id]);
        } else {
            throw new Exception("Transaction Recording Failed: " . $txnResult['error']);
        }

        logActivity($pdo, $created_by, "Added new expense: " . $description . " (Amount: " . $amount . ")");
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Expense added successfully', 'id' => $expense_id]);
    } else {
        throw new Exception('Failed to insert expense');
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Database error in add_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error in add_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
