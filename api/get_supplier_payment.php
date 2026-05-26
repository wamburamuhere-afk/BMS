<?php
// scope-audit: skip — supplier payment lookup helper; scope deferred to Phase G-2
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$payment_id = $_GET['id'] ?? '';

if (empty($payment_id)) {
    echo json_encode(['success' => false, 'message' => 'Payment ID is required']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT sp.*, s.supplier_name, po.order_number, u.username as created_by_name
        FROM supplier_payments sp
        LEFT JOIN suppliers s ON sp.supplier_id = s.supplier_id
        LEFT JOIN purchase_orders po ON sp.purchase_order_id = po.purchase_order_id
        LEFT JOIN users u ON sp.created_by = u.user_id
        WHERE sp.payment_id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
        echo json_encode(['success' => true, 'data' => $payment]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
