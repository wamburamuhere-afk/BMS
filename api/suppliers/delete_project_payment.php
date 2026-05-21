<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canDelete('received_invoices')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

csrf_check();

$id = intval($_POST['payment_id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'message' => 'Payment ID required']); exit; }

try {
    $cur = $pdo->prepare("SELECT status, amount, purchase_order_id FROM supplier_payments WHERE payment_id = ?");
    $cur->execute([$id]);
    $payment = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$payment) { echo json_encode(['success' => false, 'message' => 'Payment not found']); exit; }
    if ($payment['status'] === 'approved') {
        echo json_encode(['success' => false, 'message' => 'Approved payments cannot be deleted']); exit;
    }

    $pdo->beginTransaction();

    // Reverse paid_amount on PO
    $po = $pdo->prepare("SELECT grand_total, paid_amount FROM purchase_orders WHERE purchase_order_id = ?");
    $po->execute([$payment['purchase_order_id']]);
    $poRow = $po->fetch(PDO::FETCH_ASSOC);
    if ($poRow) {
        $newPaid   = max(0, floatval($poRow['paid_amount']) - floatval($payment['amount']));
        $payStatus = $newPaid >= floatval($poRow['grand_total']) ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
        $pdo->prepare("UPDATE purchase_orders SET paid_amount = ?, payment_status = ? WHERE purchase_order_id = ?")
            ->execute([$newPaid, $payStatus, $payment['purchase_order_id']]);
    }

    // Soft-delete: set status to cancelled
    $pdo->prepare("UPDATE supplier_payments SET status = 'cancelled', updated_at = NOW() WHERE payment_id = ?")
        ->execute([$id]);

    $pdo->commit();
    logActivity($pdo, $_SESSION['user_id'], "Deleted supplier payment #$id");
    echo json_encode(['success' => true, 'message' => 'Payment deleted.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('delete_project_payment: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
