<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

header('Content-Type: application/json');

try {
    // Check if user is authenticated
    if (!isAuthenticated()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Get expense ID
    $expense_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

    if ($expense_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid expense ID']);
        exit;
    }

    // Fetch expense with related data
    $sql = "
        SELECT 
            e.*,
            ea.account_name as expense_account_name,
            ba.account_name as bank_account_name,
            u.username as created_by_name,
            u2.username as updated_by_name
        FROM expenses e
        LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id
        LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id
        LEFT JOIN users u ON e.created_by = u.user_id
        LEFT JOIN users u2 ON e.updated_by = u2.user_id
        WHERE e.expense_id = ?
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Expense not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $expense
    ]);

} catch (PDOException $e) {
    error_log("Database error in get_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error in get_expense.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
