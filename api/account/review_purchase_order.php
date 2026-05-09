<?php
// File: api/account/review_purchase_order.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canReview('purchase_orders')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to review purchase orders']);
    exit;
}

try {
    global $pdo;
    $po_id = isset($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : 0;
    
    if (!$po_id) throw new Exception("Invalid Purchase Order ID");

    // Get current status and ensure it's pending
    $stmt = $pdo->prepare("SELECT status FROM purchase_orders WHERE purchase_order_id = ?");
    $stmt->execute([$po_id]);
    $current_status = $stmt->fetchColumn();

    if ($current_status !== 'pending' && $current_status !== 'draft') {
        throw new Exception("Only pending or draft orders can be submitted for review. Current status: " . $current_status);
    }

    // Snapshot reviewer info
    $reviewer_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty($reviewer_name)) $reviewer_name = $_SESSION['username'] ?? 'System';
    $reviewer_role = $_SESSION['user_role'] ?? 'Staff';

    $stmt = $pdo->prepare("
        UPDATE purchase_orders 
        SET status = 'review', 
            reviewed_by_name = ?, 
            reviewed_by_role = ?, 
            reviewed_at = NOW() 
        WHERE purchase_order_id = ?
    ");
    $stmt->execute([$reviewer_name, $reviewer_role, $po_id]);

    echo json_encode(['success' => true, 'message' => 'Purchase Order submitted for review successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
