<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/expense_posting.php';  // voucherIsAccrued / reverseVoucherAccrual

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
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) throw new Exception("Invalid ID");

    // Phase C — block deletes against vouchers on projects not in user scope
    assertScopeForRecord('payment_vouchers', 'id', $id);

    // Fetch status before deleting.
    $row = $pdo->prepare("SELECT status FROM payment_vouchers WHERE id = ?");
    $row->execute([$id]);
    $status = $row->fetchColumn();
    if ($status === false) throw new Exception("Voucher not found.");

    // A voucher with ANY recorded payment is LOCKED: deleting it would orphan the
    // posted payment entries (Dr Accrued/Expense / Cr Bank), the bank-register rows,
    // and the moved bank balance — leaving money off-book. Reverse the payment(s)
    // first. (Mirrors the paid-expense delete lock.)
    $pc = $pdo->prepare("SELECT COUNT(*) FROM voucher_payments WHERE voucher_id = ?");
    $pc->execute([$id]);
    $paidCount = (int)$pc->fetchColumn();
    if (in_array($status, ['paid', 'partially_paid'], true) || $paidCount > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This voucher has recorded payment(s) and is locked. Reverse the payment(s) first before deleting.']);
        exit();
    }

    // Approved-but-unpaid: an accrual was posted at approval (Dr Expense / Cr Accrued
    // Expenses) with no payment transaction. Unwind it before delete, or the P&L
    // Expense and the Accrued Expenses liability stay overstated with no source doc.
    // Idempotent (keyed on voucher_accrual_void); no-op if it was never accrued.
    $pdo->beginTransaction();

    if (voucherIsAccrued($pdo, $id)) {
        reverseVoucherAccrual($pdo, $id, (int)$_SESSION['user_id']);
    }

    $pdo->prepare("DELETE FROM payment_vouchers WHERE id = ?")->execute([$id]);

    $pdo->commit();

    // Phase 3a — log every payment-voucher delete (financial audit trail)
    logActivity($pdo, $_SESSION['user_id'], "Deleted Payment Voucher", "Voucher ID: $id (status was '$status')");

    echo json_encode(['success' => true, 'message' => 'Voucher deleted successfully']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
