<?php
// File: api/delete_leave.php
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
    
    if (!$leave_id) {
        throw new Exception("Leave ID is required");
    }

    $query = "DELETE FROM leaves WHERE leave_id = ? AND status IN ('pending', 'cancelled')";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$leave_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Leave record not found or cannot be deleted in current status");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Leave record deleted successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
