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
if (!canDelete('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete sales orders']);
    exit;
}

try {
    global $pdo;
    
    $order_id = $_POST['order_id'] ?? 0;

    if (!$order_id) {
        throw new Exception("Missing order ID");
    }

    // Phase C — block deletes against sales orders on projects not in user scope
    assertScopeForRecord('sales_orders', 'sales_order_id', $order_id);

    // Check if it's a quote or a draft order
    $stmt = $pdo->prepare("SELECT order_number, status, is_quote FROM sales_orders WHERE sales_order_id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order/Quotation not found");
    }

    if ($order['is_quote'] != 1 && $order['status'] !== 'draft') {
        throw new Exception("Only draft orders can be deleted. Quotations can be deleted at any time.");
    }

    $pdo->beginTransaction();

    // Delete items first
    $pdo->prepare("DELETE FROM sales_order_items WHERE order_id = ?")->execute([$order_id]);
    
    // Delete order
    $pdo->prepare("DELETE FROM sales_orders WHERE sales_order_id = ?")->execute([$order_id]);

    $pdo->commit();
    
    // Log Activity
    $type_label = ($order['is_quote'] == 1) ? 'Quotation' : 'Sales Order';
    $user_name = $_SESSION['username'] ?? 'User';
    $order_num = $order['order_number'] ?? 'Unknown';
    $description = "deleted " . strtolower($type_label) . " #$order_num with id $order_id";

    logActivity($pdo, $_SESSION['user_id'], "Delete $type_label", $description);

    echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
