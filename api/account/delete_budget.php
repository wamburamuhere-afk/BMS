<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canDelete('budgets')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete budgets']);
    exit();
}

$budget_id = (int)($_POST['budget_id'] ?? 0);

if (!$budget_id) {
    echo json_encode(['success' => false, 'message' => 'Budget ID is required']);
    exit();
}

assertScopeForRecord('budgets', 'budget_id', $budget_id);

$budget = $pdo->prepare("SELECT * FROM budgets WHERE budget_id = ?");
$budget->execute([$budget_id]);
$budget = $budget->fetch(PDO::FETCH_ASSOC);

if (!$budget) {
    echo json_encode(['success' => false, 'message' => 'Budget not found']);
    exit();
}

if ($budget['status'] === 'approved' && !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Cannot delete an approved budget. Reject it first or ask an administrator.']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Reverse GL entries for every paid expense linked to this budget
    $paid = $pdo->prepare(
        "SELECT expense_id, transaction_id FROM expenses
          WHERE budget_id = ? AND status = 'paid' AND transaction_id IS NOT NULL"
    );
    $paid->execute([$budget_id]);
    $paid_expenses = $paid->fetchAll(PDO::FETCH_ASSOC);

    foreach ($paid_expenses as $exp) {
        reverseOutflow($pdo, (int)$exp['transaction_id']);
        $pdo->prepare("UPDATE expenses SET status = 'rejected', transaction_id = NULL WHERE expense_id = ?")
            ->execute([$exp['expense_id']]);
    }

    $pdo->prepare("DELETE FROM budgets WHERE budget_id = ?")->execute([$budget_id]);

    $pdo->commit();

    $reversed = count($paid_expenses);
    $msg = $reversed > 0
        ? "Budget deleted and $reversed GL posting(s) reversed."
        : 'Budget deleted successfully.';

    logActivity($pdo, $_SESSION['user_id'],
        "Deleted budget #$budget_id (Category: {$budget['category_id']}, Allocated: {$budget['allocated_amount']}) — $reversed GL posting(s) reversed");

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("delete_budget.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
