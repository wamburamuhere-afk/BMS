<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check permissions
if (!canEdit('purchase_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to update purchase returns']);
    exit;
}

try {
    global $pdo;
    
    $return_id = $_POST['return_id'] ?? 0;
    $status = $_POST['status'] ?? '';

    if (!$return_id || !$status) {
        throw new Exception("Missing return ID or status");
    }

    $valid_statuses = ['pending', 'approved', 'completed', 'rejected', 'cancelled'];
    if (!in_array($status, $valid_statuses)) {
        throw new Exception("Invalid status");
    }

    // Returns three-approval slice: pending->reviewed and reviewed->approved
    // transitions must go through the canonical endpoints
    // (api/account/review_purchase_return.php and approve_purchase_return.php)
    // so the workflow_signatures row is captured. This endpoint stays usable
    // for post-approval transitions (approved -> completed/cancelled/rejected).
    if (in_array($status, ['reviewed', 'approved'], true)) {
        throw new Exception("Use the canonical Review/Approve buttons on the return view to perform this transition.");
    }

    $stmt = $pdo->prepare("UPDATE purchase_returns SET status = ?, updated_at = NOW(), updated_by = ? WHERE purchase_return_id = ?");
    $stmt->execute([$status, $_SESSION['user_id'], $return_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Return not found or status already set");
    }

    // Phase 3a — financial-write audit trail.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Purchase Return Status", "Purchase Return ID: $return_id, new status: $status");

    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
