<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';
require_once __DIR__ . '/../../core/money_guard.php';     // postOutflowOrFail / accountFundsWarning
require_once __DIR__ . '/../../core/expense_posting.php';
require_once __DIR__ . '/../../core/bank_register.php';
require_once __DIR__ . '/../../core/gl_accounts.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canEdit('payment_vouchers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: cannot record payment']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}
csrf_check();

try {
    $voucher_id          = intval($_POST['id'] ?? 0);
    $payment_amount      = round((float)($_POST['payment_amount'] ?? 0), 2);
    $paid_from_account_id = intval($_POST['paid_from_account_id'] ?? 0);
    $payment_method      = trim($_POST['payment_method'] ?? 'cash');
    $payment_date        = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['payment_date'] ?? ''))
                           ? $_POST['payment_date'] : date('Y-m-d');
    $reference_number    = trim($_POST['payment_reference'] ?? '') ?: null;

    if (!$voucher_id) throw new Exception('Invalid voucher.');
    if ($payment_amount <= 0) throw new Exception('Payment amount must be greater than zero.');
    if (!$paid_from_account_id) throw new Exception('Please choose the account the money is paid from.');
    if (!bankAccountResolve($pdo, $paid_from_account_id)) {
        throw new Exception('The "Paid From" account must be an active cash/bank account.');
    }

    // Scope check
    assertScopeForRecord('payment_vouchers', 'id', $voucher_id);

    // Fetch voucher
    $vrow = $pdo->prepare("SELECT * FROM payment_vouchers WHERE id = ?");
    $vrow->execute([$voucher_id]);
    $voucher = $vrow->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) throw new Exception('Voucher not found.');

    if (!in_array($voucher['status'], ['approved', 'partially_paid'])) {
        throw new Exception("Payments can only be recorded on approved or partially paid vouchers.");
    }

    // Calculate already paid and balance — excludes reversed payments, which no
    // longer count toward what was actually paid.
    $paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM voucher_payments WHERE voucher_id = ? AND reversed_at IS NULL");
    $paid_stmt->execute([$voucher_id]);
    $already_paid = round((float)$paid_stmt->fetchColumn(), 2);
    $balance_due  = round((float)$voucher['amount'] - $already_paid, 2);

    if ($payment_amount > $balance_due + 0.005) {
        throw new Exception("Payment amount ($payment_amount) exceeds outstanding balance ($balance_due).");
    }

    // Handle attachment upload
    $attachment_path = null;
    if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] == 0) {
        // Written path and STORED path must come from the same pair — see
        // core/tenant_bootstrap.php. Unprefixed on the legacy install.
        require_once __DIR__ . '/../../core/tenant_bootstrap.php';
        $proj_folder = $voucher['project_id'] ?: 'general';
        $upload_sub  = "projects/$proj_folder/vouchers";
        $upload_dir  = bmsUploadsDir($upload_sub);   // creates dir + .htaccess guard
        $file_ext  = pathinfo($_FILES['attachment_file']['name'], PATHINFO_EXTENSION);
        $file_name = 'vpay_' . time() . '_' . uniqid() . '.' . $file_ext;
        assertUploadWithinQuota($pdo, (int)$_FILES['attachment_file']['size']);
        if (move_uploaded_file($_FILES['attachment_file']['tmp_name'], $upload_dir . $file_name)) {
            $attachment_path = bmsUploadsRel($upload_sub) . $file_name;
            registerFileInLibrary($pdo, $attachment_path, $_FILES['attachment_file']['name'],
                $_FILES['attachment_file']['size'], 'Payment Proof - Voucher #' . $voucher_id,
                'voucher,payment,finance', $_SESSION['user_id']);
        }
    }

    // Post GL: Dr Accrued Expenses (or Expense) / Cr Bank — for this payment amount only
    $v_exp = (int)($voucher['expense_account_id'] ?? 0);
    $v_proj = !empty($voucher['project_id']) ? (int)$voucher['project_id'] : null;

    if (voucherIsAccrued($pdo, $voucher_id)) {
        $debitAccount = (int)(accruedExpensesAccountId($pdo) ?: ($v_exp ?: defaultPayableAccountId($pdo)));
    } else {
        $debitAccount = $v_exp ?: defaultPayableAccountId($pdo);
    }

    // MONEY-SAFETY (Step 5): I3 "warn but allow" — note a short balance, never block.
    $funds_warn = accountFundsWarning($pdo, (int)$paid_from_account_id, $payment_amount);

    // Wrap the GL post + bank register + payment row + status change in ONE transaction
    // so a posting failure leaves NOTHING half-recorded (the handler had no transaction).
    $pdo->beginTransaction();

    // Post GL (Dr Accrued/Expense / Cr Bank) — FAIL LOUDLY with the real reason; on
    // failure the whole transaction rolls back rather than saving an off-book payment.
    $gl_txn_id = postOutflowOrFail(
        $pdo, 'voucher', $paid_from_account_id, $debitAccount,
        $payment_amount, $payment_date, $voucher['voucher_number'],
        "Voucher {$voucher['voucher_number']} — {$voucher['payee_name']}", $v_proj
    );

    // Record bank transaction. recordBankTransaction() dedupes on
    // (bank_account_id, reference_number, type) — a second partial payment on the
    // SAME voucher to the SAME account would otherwise collide with the first
    // payment's reference and silently record nothing. Suffix the reference for
    // the 2nd+ payment so each gets its own row (the first payment's reference is
    // left exactly as the voucher number — no behaviour change for the common
    // single-payment case).
    $paidCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM voucher_payments WHERE voucher_id = " . (int)$voucher_id . " AND reversed_at IS NULL")->fetchColumn();
    $bank_ref = $paidCountBefore > 0 ? $voucher['voucher_number'] . '-P' . ($paidCountBefore + 1) : $voucher['voucher_number'];
    $bank_txn_id = recordBankTransaction($pdo, $paid_from_account_id, $payment_amount, 'withdrawal',
        $payment_date, $bank_ref,
        "Voucher {$voucher['voucher_number']} — {$voucher['payee_name']}", (int)$_SESSION['user_id']);

    // Insert into voucher_payments
    $pdo->prepare("
        INSERT INTO voucher_payments
            (voucher_id, amount, paid_from_account_id, payment_date, payment_method,
             reference_number, gl_transaction_id, bank_transaction_id, attachment, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $voucher_id, $payment_amount, $paid_from_account_id, $payment_date,
        $payment_method, $reference_number, $gl_txn_id, $bank_txn_id, $attachment_path, $_SESSION['user_id']
    ]);

    // Determine new voucher status
    $new_amount_paid = round($already_paid + $payment_amount, 2);
    $new_status = ($new_amount_paid >= (float)$voucher['amount'] - 0.005) ? 'paid' : 'partially_paid';

    $pdo->prepare("UPDATE payment_vouchers SET status = ?, paid_from_account_id = ?, payment_date = ? WHERE id = ?")
        ->execute([$new_status, $paid_from_account_id, $payment_date, $voucher_id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Recorded voucher payment",
        "Voucher #{$voucher['voucher_number']}, amount: $payment_amount, status: $new_status");

    $balance_remaining = round((float)$voucher['amount'] - $new_amount_paid, 2);
    $msg = $new_status === 'paid'
         ? 'Voucher fully paid and recorded.'
         : 'Partial payment recorded. Balance due: ' . number_format($balance_remaining, 2);
    if ($funds_warn) $msg .= ' ' . $funds_warn;
    echo json_encode([
        'success'           => true,
        'message'           => $msg,
        'new_status'        => $new_status,
        'balance_remaining' => $balance_remaining,
        'funds_warning'     => $funds_warn,
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
