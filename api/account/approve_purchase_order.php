<?php
// File: api/account/approve_purchase_order.php
// Workflow transition: reviewed → approved. Stamps approved_by + audit snapshot.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/workflow.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canApprove('purchase_orders')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to approve purchase orders']);
    exit;
}

try {
    global $pdo;
    $po_id = isset($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : 0;
    if (!$po_id) throw new Exception("Invalid Purchase Order ID");

    // Phase C — block approvals against POs on projects not in user scope
    assertScopeForRecord('purchase_orders', 'purchase_order_id', $po_id);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status FROM purchase_orders WHERE purchase_order_id = ? FOR UPDATE");
    $stmt->execute([$po_id]);
    $current_status = $stmt->fetchColumn();
    if ($current_status === false) throw new Exception("Purchase Order not found");

    assertApprovable($current_status);

    $actor = workflowActorSnapshot();

    $stmt = $pdo->prepare("
        UPDATE purchase_orders
        SET status            = 'approved',
            approved_by       = ?,
            approved_by_name  = ?,
            approved_by_role  = ?,
            approved_at       = NOW()
        WHERE purchase_order_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $actor['name'], $actor['role'], $po_id]);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], "Approved Purchase Order #$po_id");
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Purchase Order approved.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
