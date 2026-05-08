<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $expense_id = $_POST['expense_id'] ?? 0;
    $status = $_POST['status'] ?? '';

    if ($expense_id <= 0 || empty($status)) {
        throw new Exception('Missing required parameters');
    }

    $allowed_statuses = ['pending', 'approved', 'paid', 'rejected'];
    if (!in_array($status, $allowed_statuses)) {
        throw new Exception('Invalid status');
    }

    $stmt = $pdo->prepare("UPDATE expenses SET status = ?, updated_at = NOW(), updated_by = ? WHERE expense_id = ?");
    $result = $stmt->execute([$status, $_SESSION['user_id'], $expense_id]);

    if ($result) {
        logActivity($pdo, $_SESSION['user_id'], "Updated expense status to '$status' for expense ID: $expense_id");
        echo json_encode(['success' => true, 'message' => 'Expense status updated successfully']);
    } else {
        throw new Exception('Failed to update status');
    }

} catch (Exception $e) {
    error_log("Error in update_expense_status.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
