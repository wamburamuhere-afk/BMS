<?php
// api/account/reverse_voucher_payment.php
//
// Reverse ONE recorded voucher payment — for when a payment was recorded by
// mistake (wrong amount/account, duplicate click). There was previously no way
// to undo a voucher payment at all: delete_voucher.php locks a paid/partially_paid
// voucher and tells the user to "reverse the payment first", but that mechanism
// didn't exist. Posts the contra of the payment's ledger entry (Style B, matching
// the credit note / debit note / statutory remittance reversal mechanisms),
// removes that payment's own bank-register row, and recomputes the voucher's
// status from its remaining payments — all in one transaction.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';   // assertScopeForRecord
require_once __DIR__ . '/../../core/expense_posting.php'; // reverseVoucherPayment

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
// Reversing a payment undoes real money movement — gate at the same tier as
// approving/paying a voucher, not plain edit.
if (!canApprove('payment_vouchers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to reverse a voucher payment']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$voucher_payment_id = (int)($_POST['voucher_payment_id'] ?? 0);
if ($voucher_payment_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
    exit;
}

try {
    global $pdo;

    $vp = $pdo->prepare("SELECT id, voucher_id, amount, reversed_at FROM voucher_payments WHERE id = ?");
    $vp->execute([$voucher_payment_id]);
    $payment = $vp->fetch(PDO::FETCH_ASSOC);
    if (!$payment) { echo json_encode(['success' => false, 'message' => 'Voucher payment not found']); exit; }
    if (!empty($payment['reversed_at'])) { echo json_encode(['success' => false, 'message' => 'This payment has already been reversed.']); exit; }

    // Confirm project scope on the parent voucher before changing anything.
    assertScopeForRecord('payment_vouchers', 'id', (int)$payment['voucher_id']);

    $uid = (int)$_SESSION['user_id'];
    $pdo->beginTransaction();

    $result = reverseVoucherPayment($pdo, $voucher_payment_id, $uid);
    if (empty($result['reversed'])) {
        throw new Exception('Could not reverse the payment (' . ($result['reason'] ?? 'unknown') . '). Nothing was changed.');
    }

    $pdo->commit();

    $voucher = $pdo->prepare("SELECT voucher_number FROM payment_vouchers WHERE id = ?");
    $voucher->execute([(int)$payment['voucher_id']]);
    $voucherNumber = $voucher->fetchColumn() ?: ('#' . $payment['voucher_id']);

    logActivity($pdo, $uid, "Reversed payment of " . number_format((float)$payment['amount'], 2) . " on voucher $voucherNumber");
    if (function_exists('logAudit')) {
        logAudit($pdo, $uid, 'voucher_payment_reversed', [
            'entity_type' => 'voucher_payment', 'entity_id' => $voucher_payment_id,
            'old_values'  => ['reversed' => false],
            'new_values'  => ['reversed' => true, 'voucher_new_status' => $result['voucher_new_status'] ?? null],
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Payment reversed — the ledger and bank record have been undone.',
        'voucher_new_status' => $result['voucher_new_status'] ?? null,
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('reverse_voucher_payment error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
