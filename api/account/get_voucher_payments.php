<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canView('payment_vouchers')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$voucher_id = intval($_GET['voucher_id'] ?? 0);
if (!$voucher_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid voucher ID']);
    exit();
}

try {
    assertScopeForRecord('payment_vouchers', 'id', $voucher_id);

    $stmt = $pdo->prepare("
        SELECT vp.id, vp.amount, vp.payment_date, vp.payment_method,
               vp.reference_number, vp.created_at,
               a.account_name AS bank_name, a.account_code AS bank_code,
               u.username AS paid_by
        FROM voucher_payments vp
        LEFT JOIN accounts a ON vp.paid_from_account_id = a.account_id
        LEFT JOIN users u ON vp.created_by = u.user_id
        WHERE vp.voucher_id = ?
        ORDER BY vp.payment_date ASC, vp.created_at ASC
    ");
    $stmt->execute([$voucher_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_paid = array_sum(array_column($payments, 'amount'));

    echo json_encode(['success' => true, 'payments' => $payments, 'total_paid' => $total_paid]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
