<?php
require_once __DIR__ . '/../../roots.php';
global $pdo, $pdo_accounts;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get parameters
$budget_id = $_GET['budget_id'] ?? null;
$category_id = $_GET['category_id'] ?? null;
$year = $_GET['year'] ?? null;
$month = $_GET['month'] ?? null;

if ($budget_id) {
    // Get budget by ID
    $stmt = $pdo->prepare("
        SELECT b.*, 
               ec.category_name,
               u1.username as created_by_name,
               u2.username as approved_by_name
        FROM budgets b
        LEFT JOIN expense_categories ec ON b.category_id = ec.category_id
        LEFT JOIN users u1 ON b.created_by = u1.user_id
        LEFT JOIN users u2 ON b.approved_by = u2.user_id
        WHERE b.budget_id = ?
    ");
    $stmt->execute([$budget_id]);
} elseif ($category_id && $year && $month) {
    // Get budget by category and period
    $stmt = $pdo->prepare("
        SELECT b.*, 
               ec.category_name,
               u1.username as created_by_name,
               u2.username as approved_by_name
        FROM budgets b
        LEFT JOIN expense_categories ec ON b.category_id = ec.category_id
        LEFT JOIN users u1 ON b.created_by = u1.user_id
        LEFT JOIN users u2 ON b.approved_by = u2.user_id
        WHERE b.category_id = ? AND b.budget_year = ? AND b.budget_month = ?
    ");
    $stmt->execute([$category_id, $year, $month]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$budget = $stmt->fetch(PDO::FETCH_ASSOC);

if ($budget) {
    // Get expense details for this budget
    $expenses_stmt = $pdo->prepare("
        SELECT 
            COUNT(e.expense_id) as total_expenses,
            SUM(e.amount) as total_amount,
            AVG(e.amount) as average_expense,
            MIN(e.amount) as min_expense,
            MAX(e.amount) as max_expense
        FROM expenses e
        JOIN accounts a ON e.expense_account_id = a.account_id
        JOIN expense_categories ec ON a.account_name = ec.category_name
        WHERE ec.category_id = ? 
        AND YEAR(e.expense_date) = ? 
        AND MONTH(e.expense_date) = ?
        AND e.status IN ('approved', 'paid')
    ");
    $expenses_stmt->execute([$budget['category_id'], $budget['budget_year'], $budget['budget_month']]);
    $expense_stats = $expenses_stmt->fetch(PDO::FETCH_ASSOC);
    
    $budget['expense_stats'] = $expense_stats;
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $budget]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Budget not found']);
}
?>
