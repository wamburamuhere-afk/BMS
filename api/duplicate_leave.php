<?php
// File: api/duplicate_leave.php
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

if (!canCreate('leaves')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to duplicate leave records']);
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

    $stmt = $pdo->prepare("SELECT * FROM leaves WHERE leave_id = ?");
    $stmt->execute([$leave_id]);
    $leave = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$leave) {
        throw new Exception("Leave record not found");
    }

    // Prepare duplicated data
    unset($leave['leave_id']);
    $leave['status'] = 'pending';
    $leave['applied_by'] = $_SESSION['user_id'];
    $leave['created_by'] = $_SESSION['user_id'];
    $leave['created_at'] = date('Y-m-d H:i:s');
    $leave['updated_at'] = date('Y-m-d H:i:s');
    $leave['approved_by'] = null;
    $leave['approved_date'] = null;
    $leave['approved_at'] = null;

    $columns = implode(', ', array_keys($leave));
    $placeholders = implode(', ', array_fill(0, count($leave), '?'));
    
    $query = "INSERT INTO leaves ($columns) VALUES ($placeholders)";
    $stmt = $pdo->prepare($query);
    $stmt->execute(array_values($leave));

    $newLeaveId = $pdo->lastInsertId();
    logActivity($pdo, $_SESSION['user_id'], "Duplicated Leave", "Source Leave ID: $leave_id, New Leave ID: $newLeaveId");

    echo json_encode([
        'success' => true,
        'message' => 'Leave application duplicated as pending.'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
