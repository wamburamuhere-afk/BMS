<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';
global $pdo;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('budget')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change budget status']);
    exit();
}

try {
    $budget_id = (int)($_POST['budget_id'] ?? 0);
    $status    = trim($_POST['status'] ?? '');
    $reason    = $_POST['rejection_reason'] ?? null;

    if ($budget_id <= 0 || empty($status)) {
        throw new Exception('Missing required parameters');
    }

    // Phase C — block status changes against budgets on projects not in user scope
    assertScopeForRecord('budgets', 'budget_id', $budget_id);

    // Ensure the approved_at and rejection_reason columns exist (lazy migration)
    try { $pdo->exec("ALTER TABLE budgets ADD COLUMN approved_at DATETIME NULL DEFAULT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE budgets ADD COLUMN rejection_reason TEXT NULL"); } catch (PDOException $e) {}

    $pdo->beginTransaction();

    if ($status === 'approved') {

        $pdo->prepare("UPDATE budgets SET status = ?, updated_at = NOW(), approved_by = ?, approved_at = NOW() WHERE budget_id = ?")
            ->execute(['approved', $_SESSION['user_id'], $budget_id]);

    } elseif ($status === 'rejected') {

        // 1. Mark the budget rejected
        $pdo->prepare("UPDATE budgets SET status = 'rejected', updated_at = NOW(), rejection_reason = ?, approved_by = NULL, approved_at = NULL WHERE budget_id = ?")
            ->execute([$reason, $budget_id]);

        // 2. Find every paid expense linked to this budget that has a GL posting
        $paid = $pdo->prepare("SELECT expense_id, transaction_id FROM expenses WHERE budget_id = ? AND status = 'paid' AND transaction_id IS NOT NULL");
        $paid->execute([$budget_id]);
        $paid_expenses = $paid->fetchAll(PDO::FETCH_ASSOC);

        // 3. Reverse each GL posting and void the expense
        foreach ($paid_expenses as $exp) {
            reverseOutflow($pdo, (int)$exp['transaction_id']);
            $pdo->prepare("UPDATE expenses SET status = 'void', transaction_id = NULL WHERE expense_id = ?")
                ->execute([$exp['expense_id']]);
        }

        $reversed_count = count($paid_expenses);
        logActivity($pdo, $_SESSION['user_id'], "Rejected budget #$budget_id — reversed $reversed_count GL posting(s)");

    } else {

        $pdo->prepare("UPDATE budgets SET status = ?, updated_at = NOW() WHERE budget_id = ?")
            ->execute([$status, $budget_id]);

    }

    $pdo->commit();

    if ($status !== 'rejected') {
        logActivity($pdo, $_SESSION['user_id'], "Updated budget status to '$status' for budget ID: $budget_id");
    }

    $msg = ($status === 'rejected' && !empty($paid_expenses))
        ? 'Budget rejected and ' . count($paid_expenses) . ' GL posting(s) reversed.'
        : 'Budget status updated successfully.';

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error in update_budget_status.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
