<?php
// File: api/account/approve_sales_order.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canApprove('sales_orders')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve sales orders']);
    exit;
}

try {
    global $pdo;
    $so_id = isset($_POST['sales_order_id']) ? intval($_POST['sales_order_id']) : 0;
    if (!$so_id) throw new Exception("Invalid Sales Order ID");
    assertScopeForRecord('sales_orders', 'sales_order_id', $so_id);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status FROM sales_orders WHERE sales_order_id = ? FOR UPDATE");
    $stmt->execute([$so_id]);
    $current_status = $stmt->fetchColumn();
    if ($current_status === false) throw new Exception("Sales Order not found");

    assertApprovable($current_status);

    $actor = workflowActorSnapshot();

    $stmt = $pdo->prepare("
        UPDATE sales_orders
        SET status            = 'approved',
            approved_by       = ?,
            approved_by_name  = ?,
            approved_by_role  = ?,
            approved_at       = NOW()
        WHERE sales_order_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $so_id]);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Approved Sales Order #$so_id");
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Sales Order approved.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
