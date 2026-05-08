<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get parameters
$supplier_id = $_GET['id'] ?? null;

if (empty($supplier_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
    exit();
}

// Get supplier details
$stmt = $pdo->prepare("
    SELECT s.*,
           sc.category_name,
           u1.username as created_by_name,
           u2.username as updated_by_name,
           (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.supplier_id) as total_orders,
           (SELECT SUM(total_amount) FROM purchase_orders WHERE supplier_id = s.supplier_id AND status = 'completed') as total_spent,
           (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.supplier_id AND status = 'pending') as pending_orders
    FROM suppliers s
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    WHERE s.supplier_id = ? AND s.status != 'deleted'
");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if ($supplier) {
    // Get recent purchase orders
    $orders_stmt = $pdo->prepare("
        SELECT po.*, u.username as created_by_name
        FROM purchase_orders po
        LEFT JOIN users u ON po.created_by = u.user_id
        WHERE po.supplier_id = ?
        ORDER BY po.created_at DESC
        LIMIT 10
    ");
    $orders_stmt->execute([$supplier_id]);
    $recent_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $supplier['recent_orders'] = $recent_orders;
    
    // Get payment history
    $payments_stmt = $pdo->prepare("
        SELECT sp.*, u.username as created_by_name
        FROM supplier_payments sp
        LEFT JOIN users u ON sp.created_by = u.user_id
        WHERE sp.supplier_id = ?
        ORDER BY sp.payment_date DESC
        LIMIT 10
    ");
    $payments_stmt->execute([$supplier_id]);
    $payment_history = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $supplier['payment_history'] = $payment_history;
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $supplier]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
}