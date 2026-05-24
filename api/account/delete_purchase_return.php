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
if (!canDelete('purchase_returns')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete purchase returns']);
    exit;
}

try {
    global $pdo;
    
    $return_id = $_POST['return_id'] ?? 0;

    if (!$return_id) {
        throw new Exception("Missing return ID");
    }

    // Check status - usually only pending returns can be deleted
    $stmt = $pdo->prepare("SELECT status FROM purchase_returns WHERE purchase_return_id = ?");
    $stmt->execute([$return_id]);
    $status = $stmt->fetchColumn();

    if (!$status) {
        throw new Exception("Return not found");
    }

    if ($status !== 'pending' && $status !== 'draft') {
        throw new Exception("Only pending or draft returns can be deleted");
    }

    $pdo->beginTransaction();

    // Delete items first
    $pdo->prepare("DELETE FROM purchase_return_items WHERE purchase_return_id = ?")->execute([$return_id]);
    
    // Delete return
    $pdo->prepare("DELETE FROM purchase_returns WHERE purchase_return_id = ?")->execute([$return_id]);

    $pdo->commit();

    // Phase 3a — financial-write audit trail.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Purchase Return", "Purchase Return ID: $return_id");

    echo json_encode(['success' => true, 'message' => 'Purchase return deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
