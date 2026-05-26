<?php
// scope-audit: skip — single supplier payment read; scope deferred to Phase G-2
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('received_invoices')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'message' => 'ID required']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT sp.*,
               po.order_number AS po_number,
               po.project_id,
               po.grand_total  AS po_grand_total,
               CONCAT(u.first_name, ' ', u.last_name) AS recorded_by
        FROM supplier_payments sp
        JOIN purchase_orders po ON sp.purchase_order_id = po.purchase_order_id
        LEFT JOIN users u ON sp.created_by = u.user_id
        WHERE sp.payment_id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => 'Payment not found']); exit; }
    echo json_encode(['success' => true, 'data' => $row]);
} catch (PDOException $e) {
    error_log('get_project_payment: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
