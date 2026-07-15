<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';
require_once __DIR__ . '/../../core/expense_posting.php';  // postVoucherAccrual / reverseVoucherAccrual (OUT-2)

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canEdit('payment_vouchers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change voucher status']);
    exit();
}

try {
    $voucher_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $status     = $_POST['status'] ?? '';

    // Payments (approved→partially_paid→paid) are handled by record_voucher_payment.php
    $allowed_statuses = ['reviewed', 'approved', 'cancelled'];

    if (!$voucher_id || !in_array($status, $allowed_statuses)) {
        throw new Exception("Invalid parameters.");
    }

    // Phase C — block status changes on vouchers outside user project scope
    assertScopeForRecord('payment_vouchers', 'id', $voucher_id);

    // Enforce transition path
    $cur = $pdo->prepare("SELECT status FROM payment_vouchers WHERE id = ?");
    $cur->execute([$voucher_id]);
    $current_status = $cur->fetchColumn();
    if (!$current_status) throw new Exception("Voucher not found.");

    $allowed_transitions = [
        'pending'   => ['reviewed'],
        'reviewed'  => ['approved', 'cancelled'],
        'approved'  => [],          // payment transitions handled by record_voucher_payment.php
        'paid'      => [],
        'cancelled' => [],
        'draft'     => ['reviewed'], // backward-compat
    ];
    if (!in_array($status, $allowed_transitions[$current_status] ?? [])) {
        throw new Exception("Cannot move a voucher from '{$current_status}' to '{$status}'.");
    }

    // Snapshot voucher for GL posting
    $v = $pdo->prepare("SELECT amount, vouch_date, voucher_number, payee_name, project_id,
                               expense_account_id, status AS old_status
                          FROM payment_vouchers WHERE id = ?");
    $v->execute([$voucher_id]);
    $vrow = $v->fetch(PDO::FETCH_ASSOC) ?: [];

    // Stamp who reviewed/approved it — mirrors prepared_by, which is set at create.
    // Previously only `status` was updated here, so the print page's "Approved By"
    // always read blank/"Not Approved" even after a voucher was actually approved.
    if ($status === 'reviewed') {
        $pdo->prepare("UPDATE payment_vouchers SET status = ?, reviewed_by = ? WHERE id = ?")
            ->execute([$status, (int)$_SESSION['user_id'], $voucher_id]);
    } elseif ($status === 'approved') {
        $pdo->prepare("UPDATE payment_vouchers SET status = ?, approved_by = ? WHERE id = ?")
            ->execute([$status, (int)$_SESSION['user_id'], $voucher_id]);
    } else {
        $pdo->prepare("UPDATE payment_vouchers SET status = ? WHERE id = ?")
            ->execute([$status, $voucher_id]);
    }

    $v_amt  = (float)($vrow['amount'] ?? 0);
    $v_exp  = (int)($vrow['expense_account_id'] ?? 0);
    $v_proj = !empty($vrow['project_id']) ? (int)$vrow['project_id'] : null;

    // OUT-2 accrual — recognise cost in the P&L on APPROVE (Dr Expense / Cr Accrued Expenses)
    if ($status === 'approved' && $v_amt > 0 && $v_exp > 0) {
        postVoucherAccrual($pdo, (int)$voucher_id, $v_exp, $v_amt, $vrow['vouch_date'] ?: date('Y-m-d'),
            $v_proj, (int)$_SESSION['user_id'], $vrow['voucher_number'] ?? null, $vrow['payee_name'] ?? null);
    }

    // Cancel before any payment — reverse the approval accrual so the cost leaves the P&L
    if ($status === 'cancelled' && voucherIsAccrued($pdo, (int)$voucher_id)) {
        reverseVoucherAccrual($pdo, (int)$voucher_id, (int)$_SESSION['user_id']);
    }

    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Payment Voucher Status",
        "Voucher ID: $voucher_id, new status: $status");

    echo json_encode(['success' => true, 'message' => 'Voucher status updated to ' . $status]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
