<?php
// File: api/reject_leave.php
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $leave_id = isset($_POST['leave_id']) ? intval($_POST['leave_id']) : 0;
    $reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';
    
    if (!$leave_id) {
        throw new Exception("Leave ID is required");
    }
    if (empty($reason)) {
        throw new Exception("Rejection reason is required");
    }

    $query = "UPDATE leaves SET 
        status = 'rejected',
        notes = CONCAT(COALESCE(notes, ''), '\nRejection Reason: ', ?),
        updated_at = NOW()
    WHERE leave_id = ? AND status = 'pending'";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$reason, $leave_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Leave record not found or already processed");
    }

    logActivity($pdo, $_SESSION['user_id'], "Rejected Leave", "Leave ID: $leave_id, Reason: $reason");

    echo json_encode([
        'success' => true,
        'message' => 'Leave application rejected'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
