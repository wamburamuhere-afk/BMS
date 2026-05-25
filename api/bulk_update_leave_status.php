<?php
// File: api/bulk_update_leave_status.php
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
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to bulk update leave status']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $leave_ids = isset($_POST['leave_ids']) ? $_POST['leave_ids'] : [];
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';
    
    if (empty($leave_ids) || !is_array($leave_ids)) {
        throw new Exception("No leaves selected");
    }
    
    $valid_statuses = ['approved', 'rejected', 'cancelled'];
    if (!in_array($status, $valid_statuses)) {
        throw new Exception("Invalid status");
    }

    // Phase D — gate each leave against scope before bulk update
    if (function_exists('assertScopeForEmployeeRecord')) {
        foreach ($leave_ids as $lid) {
            assertScopeForEmployeeRecord('leaves', 'leave_id', intval($lid));
        }
    }

    $placeholders = implode(',', array_fill(0, count($leave_ids), '?'));
    
    $pdo->beginTransaction();

    if ($status === 'approved') {
        $query = "UPDATE leaves SET 
            status = 'approved',
            approved_by = ?,
            approved_date = NOW(),
            approved_at = NOW(),
            updated_at = NOW()
        WHERE leave_id IN ($placeholders) AND status = 'pending'";
        $params = array_merge([$_SESSION['user_id']], $leave_ids);
    } else {
        $query = "UPDATE leaves SET 
            status = ?,
            updated_at = NOW()
        WHERE leave_id IN ($placeholders) AND status = 'pending'";
        $params = array_merge([$status], $leave_ids);
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $affected = $stmt->rowCount();

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Bulk Updated Leave Status", "Status: $status, Affected: $affected, IDs: " . implode(',', $leave_ids));

    echo json_encode([
        'success' => true,
        'message' => "Successfully updated $affected leave(s) to $status"
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
