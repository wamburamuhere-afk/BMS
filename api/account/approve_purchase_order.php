<?php
// File: api/account/approve_purchase_order.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

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

    // Get current status and ensure it's in review
    $stmt = $pdo->prepare("SELECT status FROM purchase_orders WHERE purchase_order_id = ?");
    $stmt->execute([$po_id]);
    $current_status = $stmt->fetchColumn();

    if ($current_status !== 'review') {
        throw new Exception("Only orders in 'review' status can be approved. Current status: " . $current_status);
    }

    // Snapshot approver info
    $approver_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty($approver_name)) $approver_name = $_SESSION['username'] ?? 'System';
    $approver_role = $_SESSION['user_role'] ?? 'Staff';

    $stmt = $pdo->prepare("
        UPDATE purchase_orders 
        SET status = 'approved', 
            approved_by = ?,
            approved_by_name = ?, 
            approved_by_role = ?, 
            approved_at = NOW() 
        WHERE purchase_order_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $approver_name, $approver_role, $po_id]);

    echo json_encode(['success' => true, 'message' => 'Purchase Order approved successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
