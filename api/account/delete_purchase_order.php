<?php
// File: api/account/delete_purchase_order.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Ensure user has permission to delete (usually Admin only, but logic depends on app rules)
// if (!hasPermission('delete_orders')) { ... }

$order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Order ID']);
    exit;
}

try {
    global $pdo;

    assertScopeForRecord('purchase_orders', 'purchase_order_id', $order_id);

    $pdo->beginTransaction();

    // 1. Delete Items first (foreign key constraint usually handles this if ON DELETE CASCADE, but manual is safer)
    $stmtItems = $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?");
    $stmtItems->execute([$order_id]);

    // 2. Delete Order Header
    $stmtOrder = $pdo->prepare("DELETE FROM purchase_orders WHERE purchase_order_id = ?");
    $stmtOrder->execute([$order_id]);

    if ($stmtOrder->rowCount() > 0) {
        $pdo->commit();
        // Phase 3a — financial-write audit trail.
        logActivity($pdo, $_SESSION['user_id'], "Deleted Purchase Order", "PO ID: $order_id");
        echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);
    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Order could not be deleted or does not exist']);
    }

} catch (PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
