<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => t('Method not allowed')]);
    exit;
}

// Check permissions
if (!canEdit('purchase_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access Denied: You do not have permission to update purchase orders')]);
    exit;
}

$purchase_order_id = $_POST['purchase_order_id'] ?? 0;
$status = $_POST['status'] ?? '';

if (!$purchase_order_id || !$status) {
    echo json_encode(['success' => false, 'message' => t('Purchase Order ID and status required')]);
    exit;
}

try {
    global $pdo;

    assertScopeForRecord('purchase_orders', 'purchase_order_id', (int)$purchase_order_id);

    // Check if order exists
    $stmt = $pdo->prepare("SELECT status FROM purchase_orders WHERE purchase_order_id = ?");
    $stmt->execute([$purchase_order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception(t('Purchase Order not found'));
    }

    $update_sql = "UPDATE purchase_orders SET status = ?, updated_at = NOW()";
    $update_params = [$status];

    if ($status === 'approved') {
        $update_sql .= ", approved_by = ?, approved_at = NOW()";
        $update_params[] = $_SESSION['user_id'];
    }

    $update_sql .= " WHERE purchase_order_id = ?";
    $update_params[] = $purchase_order_id;

    $stmt = $pdo->prepare($update_sql);
    $result = $stmt->execute($update_params);
    
    if ($result) {
        // Phase 3a — financial-write audit trail.
        logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Purchase Order Status", "PO ID: $purchase_order_id, new status: $status");
        echo json_encode(['success' => true, 'message' => sprintf(t('Purchase Order status updated to %s'), $status)]);
    } else {
        echo json_encode(['success' => false, 'message' => t('Failed to update status')]);
    }

} catch (Exception $e) {
    error_log("Error updating PO status: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
