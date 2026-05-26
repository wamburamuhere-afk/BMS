<?php
// scope-audit: skip — supplier payments list; scope deferred to Phase G-2
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('received_invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$action      = $_GET['action']      ?? 'list';
$supplier_id = intval($_GET['supplier_id'] ?? 0);
$project_id  = intval($_GET['project_id']  ?? 0);

if (!$supplier_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'supplier_id and project_id are required']);
    exit;
}

// ── action=get_pos : POs for this supplier+project (for payment modal dropdown) ──
if ($action === 'get_pos') {
    try {
        $stmt = $pdo->prepare("
            SELECT purchase_order_id, order_number, grand_total, paid_amount, currency
            FROM purchase_orders
            WHERE supplier_id = ? AND project_id = ?
              AND status NOT IN ('cancelled', 'draft')
            ORDER BY order_date DESC
        ");
        $stmt->execute([$supplier_id, $project_id]);
        $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $pos]);
    } catch (PDOException $e) {
        error_log('get_project_payments get_pos: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error.']);
    }
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT sp.payment_id,
               sp.payment_number,
               sp.payment_date,
               sp.amount,
               sp.currency,
               sp.payment_method,
               sp.reference_number,
               sp.bank_name,
               sp.cheque_number,
               sp.transaction_id,
               sp.notes,
               sp.status,
               po.order_number  AS po_number,
               po.purchase_order_id,
               CONCAT(u.first_name, ' ', u.last_name) AS recorded_by
        FROM supplier_payments sp
        JOIN purchase_orders po ON sp.purchase_order_id = po.purchase_order_id
        LEFT JOIN users u ON sp.created_by = u.user_id
        WHERE po.project_id = ?
          AND sp.supplier_id = ?
          AND sp.status != 'cancelled'
        ORDER BY sp.payment_date DESC, sp.created_at DESC
    ");
    $stmt->execute([$project_id, $supplier_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = array_sum(array_column($payments, 'amount'));

    echo json_encode([
        'success'  => true,
        'payments' => $payments,
        'total'    => $total,
        'currency' => !empty($payments) ? $payments[0]['currency'] : 'TZS',
    ]);
} catch (PDOException $e) {
    error_log('suppliers/get_project_payments: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
