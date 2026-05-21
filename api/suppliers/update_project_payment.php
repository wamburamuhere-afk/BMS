<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('received_invoices')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

csrf_check();

$id               = intval($_POST['payment_id']       ?? 0);
$purchase_order_id = intval($_POST['purchase_order_id'] ?? 0);
$payment_date     = $_POST['payment_date']     ?? '';
$amount           = floatval($_POST['amount']  ?? 0);
$currency         = trim($_POST['currency']    ?? 'TZS');
$payment_method   = trim($_POST['payment_method']   ?? '');
$reference_number = trim($_POST['reference_number'] ?? '');
$notes            = trim($_POST['notes']             ?? '');

if (!$id)             { echo json_encode(['success' => false, 'message' => 'Payment ID required']); exit; }
if ($amount <= 0)     { echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']); exit; }
if (!$payment_method) { echo json_encode(['success' => false, 'message' => 'Payment method is required']); exit; }

try {
    // Only pending payments can be edited
    $cur = $pdo->prepare("SELECT status, amount AS old_amount, purchase_order_id AS old_po FROM supplier_payments WHERE payment_id = ?");
    $cur->execute([$id]);
    $payment = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$payment) { echo json_encode(['success' => false, 'message' => 'Payment not found']); exit; }
    if ($payment['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Only pending payments can be edited']); exit;
    }

    $pdo->beginTransaction();

    // Reverse old amount on the PO, apply new amount
    $oldPo   = intval($payment['old_po']);
    $oldAmt  = floatval($payment['old_amount']);
    $targetPo = $purchase_order_id ?: $oldPo;

    // Reverse old
    $pdo->prepare("UPDATE purchase_orders SET paid_amount = GREATEST(0, paid_amount - ?) WHERE purchase_order_id = ?")
        ->execute([$oldAmt, $oldPo]);

    // Apply new
    $poRow = $pdo->prepare("SELECT grand_total, paid_amount FROM purchase_orders WHERE purchase_order_id = ?");
    $poRow->execute([$targetPo]);
    $po = $poRow->fetch(PDO::FETCH_ASSOC);
    $newPaid   = floatval($po['paid_amount']) + $amount;
    $payStatus = $newPaid >= floatval($po['grand_total']) ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');

    $pdo->prepare("UPDATE purchase_orders SET paid_amount = ?, payment_status = ? WHERE purchase_order_id = ?")
        ->execute([$newPaid, $payStatus, $targetPo]);

    $pdo->prepare("
        UPDATE supplier_payments
        SET purchase_order_id = ?, payment_date = ?, amount = ?, currency = ?,
            payment_method = ?, reference_number = ?, notes = ?, updated_at = NOW()
        WHERE payment_id = ?
    ")->execute([$targetPo, $payment_date, $amount, $currency, $payment_method,
                 $reference_number ?: null, $notes ?: null, $id]);

    $pdo->commit();
    logActivity($pdo, $_SESSION['user_id'], "Updated supplier payment #$id");
    echo json_encode(['success' => true, 'message' => 'Payment updated successfully.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('update_project_payment: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
