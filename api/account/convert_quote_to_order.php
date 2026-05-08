<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check permissions
if (!canCreate('sales_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to create sales orders from quotations']);
    exit;
}

try {
    global $pdo;
    $id = $_POST['id'] ?? 0;

    if (!$id) {
        throw new Exception("Missing quotation ID");
    }

    // Convert quotation to order: set is_quote = 0 and status = 'pending'
    $stmt = $pdo->prepare("UPDATE sales_orders SET is_quote = 0, status = 'pending', updated_at = NOW(), updated_by = ? WHERE sales_order_id = ? AND is_quote = 1");
    $stmt->execute([$_SESSION['user_id'], $id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("Quotation not found or already converted");
    }

    // Log Activity
    $stmt_num = $pdo->prepare("SELECT order_number FROM sales_orders WHERE sales_order_id = ?");
    $stmt_num->execute([$id]);
    $order_number = $stmt_num->fetchColumn(); 
    
    $user_name = $_SESSION['username'] ?? 'User';
    logActivity($pdo, $_SESSION['user_id'], 'Convert Quotation', "$user_name converted Quotation #$order_number to Sales Order");

    echo json_encode(['success' => true, 'message' => 'Converted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
