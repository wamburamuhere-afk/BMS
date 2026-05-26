<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/workflow.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!canEdit('expenses')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change expense status']);
    exit;
}

try {
    $expense_id = $_POST['expense_id'] ?? 0;
    $status = $_POST['status'] ?? '';

    if ($expense_id <= 0 || empty($status)) {
        throw new Exception('Missing required parameters');
    }

    // Phase C — block status changes against expenses on projects not in user scope
    assertScopeForRecord('expenses', 'expense_id', $expense_id);

    $allowed_statuses = ['pending', 'reviewed', 'approved', 'paid', 'rejected'];
    if (!in_array($status, $allowed_statuses)) {
        throw new Exception('Invalid status');
    }

    $actor       = workflowActorSnapshot();
    $extra_update = '';
    $action       = null;

    if ($status === 'reviewed') {
        $extra_update = ', reviewed_by = ' . intval($_SESSION['user_id']);
        $action       = 'reviewed';
    } elseif ($status === 'approved') {
        $extra_update = ', approved_by = ' . intval($_SESSION['user_id']);
        $action       = 'approved';
    }

    $stmt   = $pdo->prepare("UPDATE expenses SET status = ?, updated_at = NOW(), updated_by = ? $extra_update WHERE expense_id = ?");
    $result = $stmt->execute([$status, $_SESSION['user_id'], $expense_id]);

    if (!$result) throw new Exception('Failed to update status');

    $sigResult = ['has_signature' => true];
    if ($action !== null) {
        $sigResult = workflowCaptureSignature($pdo, 'expense', $expense_id, $action,
            $_SESSION['user_id'], $actor['name'], $actor['role']);
    }

    logActivity($pdo, $_SESSION['user_id'], "Updated expense status to '$status' for expense ID: $expense_id");

    $response = ['success' => true, 'message' => 'Expense status updated successfully'];
    if (!$sigResult['has_signature']) {
        $response['sig_warning'] = 'Your electronic signature was not captured because you have no signature on file. Please set one up in E-Signatures.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in update_expense_status.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
