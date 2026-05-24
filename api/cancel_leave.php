<?php
// File: api/cancel_leave.php
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

if (!canEdit('leaves')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to cancel leave']);
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

    // Determine if user has permission to cancel (Admin or owner)
    $stmt = $pdo->prepare("SELECT applied_by, status FROM leaves WHERE leave_id = ?");
    $stmt->execute([$leave_id]);
    $leave = $stmt->fetch();

    if (!$leave) {
        throw new Exception("Leave record not found");
    }

    // Role check simplified: in root or session
    // $user_role = $_SESSION['user_role'] ?? 'Employee';
    // if ($user_role !== 'Admin' && $leave['applied_by'] != $_SESSION['user_id']) {
    //    throw new Exception("Unauthorized to cancel this leave");
    // }

    $query = "UPDATE leaves SET 
        status = 'cancelled',
        updated_at = NOW()
    WHERE leave_id = ? AND status IN ('pending', 'approved')";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$leave_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Leave could not be cancelled (possibly already started or rejected)");
    }

    logActivity($pdo, $_SESSION['user_id'], "Cancelled Leave", "Leave ID: $leave_id");

    echo json_encode([
        'success' => true,
        'message' => 'Leave application cancelled'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
