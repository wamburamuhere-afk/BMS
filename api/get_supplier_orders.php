<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$supplier_id = $_GET['supplier_id'] ?? '';

if (empty($supplier_id)) {
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT purchase_order_id, order_number, total_amount, status 
        FROM purchase_orders 
        WHERE supplier_id = ? AND status != 'cancelled'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$supplier_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $orders]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
