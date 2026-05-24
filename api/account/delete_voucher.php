<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canDelete('payment_vouchers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete payment vouchers']);
    exit();
}

try {
    $id = $_POST['id'] ?? 0;
    if (!$id) throw new Exception("Invalid ID");

    $stmt = $pdo->prepare("DELETE FROM payment_vouchers WHERE id = ?");
    $stmt->execute([$id]);

    // Phase 3a — log every payment-voucher delete (financial audit trail)
    logActivity($pdo, $_SESSION['user_id'], "Deleted Payment Voucher", "Voucher ID: $id");

    echo json_encode(['success' => true, 'message' => 'Voucher deleted successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
