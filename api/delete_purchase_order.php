<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canDelete('purchase_orders')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete purchase orders']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$po_id = intval($_POST['order_id'] ?? $_POST['id'] ?? 0);
if (!$po_id) {
    echo json_encode(['success' => false, 'message' => 'Purchase order ID is required']);
    exit();
}

try {
    $check = $pdo->prepare("SELECT purchase_order_id, order_number FROM purchase_orders WHERE purchase_order_id = ?");
    $check->execute([$po_id]);
    $po = $check->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        echo json_encode(['success' => false, 'message' => 'Purchase order not found']);
        exit();
    }

    $pdo->prepare("DELETE FROM purchase_orders WHERE purchase_order_id = ?")->execute([$po_id]);

    logActivity($pdo, $_SESSION['user_id'], "Deleted Purchase Order", "PO: " . $po['order_number'] . " (ID: $po_id)");

    echo json_encode(['success' => true, 'message' => 'Purchase order ' . $po['order_number'] . ' deleted successfully']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
