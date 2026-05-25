<?php
// File: api/approve_leave.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_leave.log');

require_once __DIR__ . '/../roots.php';

ob_clean();
header('Content-Type: application/json');

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canApprove('leaves') && !canEdit('leaves')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to approve leaves']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $leave_id = isset($_POST['leave_id']) ? intval($_POST['leave_id']) : 0;
    
    if (!$leave_id) {
        throw new Exception("Leave ID is required");
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployeeRecord')) {
        assertScopeForEmployeeRecord('leaves', 'leave_id', $leave_id);
    }

    $pdo->beginTransaction();

    $query = "UPDATE leaves SET
        status = 'approved',
        approved_by = ?,
        approved_date = NOW(),
        approved_at = NOW(),
        updated_at = NOW()
    WHERE leave_id = ? AND status = 'pending'";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$_SESSION['user_id'], $leave_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Leave record not found or already processed");
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Approved Leave", "Leave ID: $leave_id");

    echo json_encode([
        'success' => true,
        'message' => 'Leave application approved successfully'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
