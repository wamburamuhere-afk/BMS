<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    $budget_id = $_POST['budget_id'] ?? 0;
    $status    = $_POST['status'] ?? '';

    $reason = $_POST['rejection_reason'] ?? null;

    if ($budget_id <= 0 || empty($status)) {
        throw new Exception('Missing required parameters');
    }

    // Ensure the approved_at and rejection_reason columns exist (lazy migration)
    try {
        $pdo->exec("ALTER TABLE budgets ADD COLUMN approved_at DATETIME NULL DEFAULT NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE budgets ADD COLUMN rejection_reason TEXT NULL");
    } catch (PDOException $e) {}

    if ($status === 'approved') {
        $stmt = $pdo->prepare("UPDATE budgets SET status = ?, updated_at = NOW(), approved_by = ?, approved_at = NOW() WHERE budget_id = ?");
        $stmt->execute([$status, $_SESSION['user_id'], $budget_id]);
    } elseif ($status === 'rejected') {
        $stmt = $pdo->prepare("UPDATE budgets SET status = ?, updated_at = NOW(), rejection_reason = ?, approved_by = NULL, approved_at = NULL WHERE budget_id = ?");
        $stmt->execute([$status, $reason, $budget_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE budgets SET status = ?, updated_at = NOW() WHERE budget_id = ?");
        $stmt->execute([$status, $budget_id]);
    }

    logActivity($pdo, $_SESSION['user_id'], "Updated budget status to '$status' for budget ID: $budget_id");

    echo json_encode(['success' => true, 'message' => 'Budget status updated successfully']);

} catch (Exception $e) {
    error_log("Error in update_budget_status.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
