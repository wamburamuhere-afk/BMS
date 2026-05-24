<?php
// File: api/account/review_sales_order.php
// Workflow transition: pending|draft → reviewed. Stamps reviewed_by + audit snapshot.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canReview('sales_orders')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to review sales orders']);
    exit;
}

try {
    global $pdo;
    $so_id = isset($_POST['sales_order_id']) ? intval($_POST['sales_order_id']) : 0;
    if (!$so_id) throw new Exception("Invalid Sales Order ID");

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status FROM sales_orders WHERE sales_order_id = ? FOR UPDATE");
    $stmt->execute([$so_id]);
    $current_status = $stmt->fetchColumn();
    if ($current_status === false) throw new Exception("Sales Order not found");

    assertReviewable($current_status);

    $actor = workflowActorSnapshot();

    $stmt = $pdo->prepare("
        UPDATE sales_orders
        SET status            = 'reviewed',
            reviewed_by       = ?,
            reviewed_by_name  = ?,
            reviewed_by_role  = ?,
            reviewed_at       = NOW()
        WHERE sales_order_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $so_id]);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Reviewed Sales Order #$so_id");
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Sales Order marked as reviewed.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
