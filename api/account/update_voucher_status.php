<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';

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
    $status = $_POST['status'] ?? '';

    $allowed_statuses = ['draft', 'approved', 'paid', 'cancelled'];
    
    if (!$voucher_id || !in_array($status, $allowed_statuses)) {
        throw new Exception("Invalid parameters.");
    }

    // Phase C — block status changes against vouchers on projects not in user scope
    assertScopeForRecord('payment_vouchers', 'id', $voucher_id);

    // Handle Paid status extra fields
    $payment_reference = $_POST['payment_reference'] ?? null;
    $paid_from_account_id = !empty($_POST['paid_from_account_id']) ? (int)$_POST['paid_from_account_id'] : null;
    $attachment_path = null;

    // Paying requires a source account (no one-click pay without the payment form).
    if ($status === 'paid' && empty($paid_from_account_id)) {
        throw new Exception('Please choose the account the voucher is paid from (Paid From).');
    }

    if ($status === 'paid') {
        if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] == 0) {
            // Get project_id for this voucher
            $p_stmt = $pdo->prepare("SELECT project_id FROM payment_vouchers WHERE id = ?");
            $p_stmt->execute([$voucher_id]);
            $proj_id = $p_stmt->fetchColumn();
            $proj_folder = $proj_id ?: 'general';

            $upload_dir = __DIR__ . "/../../uploads/projects/$proj_folder/vouchers/";
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $file_ext = pathinfo($_FILES['attachment_file']['name'], PATHINFO_EXTENSION);
            $file_name = 'voucher_' . time() . '_' . uniqid() . '.' . $file_ext;
            if (move_uploaded_file($_FILES['attachment_file']['tmp_name'], $upload_dir . $file_name)) {
                $attachment_path = "uploads/projects/$proj_folder/vouchers/" . $file_name;
                registerFileInLibrary($pdo, $attachment_path, $_FILES['attachment_file']['name'], $_FILES['attachment_file']['size'], 'Payment Proof - Voucher #' . $voucher_id, 'voucher,payment,finance', $_SESSION['user_id']);
            }
        }
    }
    
    $sql = "UPDATE payment_vouchers SET status = ?";
    $params = [$status];

    if ($payment_reference !== null) {
        $sql .= ", reference_number = ?";
        $params[] = $payment_reference;
    }
    if ($attachment_path !== null) {
        $sql .= ", attachment = ?";
        $params[] = $attachment_path;
    }
    if ($status === 'paid') {
        $sql .= ", paid_from_account_id = ?";
        $params[] = $paid_from_account_id;
    }

    $sql .= " WHERE id = ?";
    $params[] = $voucher_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Consolidated outflow on the 'paid' transition: Dr the voucher's EXPENSE
    // account (so the cost lands in the P&L), Cr the Paid-From account. Falls back
    // to Accounts Payable only when no expense account is set on the voucher.
    if ($status === 'paid') {
        $v = $pdo->prepare("SELECT amount, vouch_date, voucher_number, payee_name, project_id, transaction_id, expense_account_id
                              FROM payment_vouchers WHERE id = ?");
        $v->execute([$voucher_id]);
        $vrow = $v->fetch(PDO::FETCH_ASSOC);
        if ($vrow && (float)$vrow['amount'] > 0) {
            if (!empty($vrow['transaction_id'])) reverseOutflow($pdo, (int)$vrow['transaction_id']); // re-pay safety
            $debitAccount = !empty($vrow['expense_account_id']) ? (int)$vrow['expense_account_id'] : defaultPayableAccountId($pdo);
            $txn = postOutflow(
                $pdo, 'voucher', $paid_from_account_id, $debitAccount,
                (float)$vrow['amount'], $vrow['vouch_date'] ?: date('Y-m-d'), $vrow['voucher_number'],
                "Voucher {$vrow['voucher_number']} — {$vrow['payee_name']}",
                $vrow['project_id'] ? (int)$vrow['project_id'] : null
            );
            if ($txn) {
                $pdo->prepare("UPDATE payment_vouchers SET transaction_id = ? WHERE id = ?")->execute([$txn, $voucher_id]);
            }
        }
    }

    // Phase 3a — voucher state changes are critical financial events
    // (especially the 'paid' transition that records actual cash movement).
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Updated Payment Voucher Status", "Voucher ID: $voucher_id, new status: $status");

    echo json_encode(['success' => true, 'message' => 'Voucher status updated' . ($status === 'paid' ? ' and payment recorded' : '')]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
