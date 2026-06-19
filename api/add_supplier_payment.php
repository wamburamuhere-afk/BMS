<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/auto_post_hook.php';
require_once __DIR__ . '/../core/payment_source.php';
require_once __DIR__ . '/../core/money_guard.php';   // postOutflowOrFail / accountFundsWarning
require_once __DIR__ . '/../core/wht.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$user_id = $_SESSION['user_id'];

if (!canCreate('supplier_payments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to add supplier payments']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get and validate input
$supplier_id = $_POST['supplier_id'] ?? '';
$purchase_order_id = !empty($_POST['purchase_order_id']) ? $_POST['purchase_order_id'] : null;
$payment_date = $_POST['payment_date'] ?? date('Y-m-d');
$amount = floatval($_POST['amount'] ?? 0);
$currency = $_POST['currency'] ?? 'TZS';
$payment_method = $_POST['payment_method'] ?? '';
$reference_number = $_POST['reference_number'] ?? '';
$notes = $_POST['notes'] ?? '';
$paid_from_account_id = !empty($_POST['paid_from_account_id']) ? (int)$_POST['paid_from_account_id'] : null;
$wht_rate_id = !empty($_POST['wht_rate_id']) ? (int)$_POST['wht_rate_id'] : null;

if (empty($supplier_id) || empty($amount) || empty($payment_method)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
    exit();
}
if (empty($paid_from_account_id)) {
    echo json_encode(['success' => false, 'message' => 'Please choose the account the payment was made from (Paid From)']);
    exit();
}

// Withholding tax (optional). Ad-hoc payments have no VAT split, so the entered
// amount is the WHT base. Reduces the cash paid; the withheld slice is owed to TRA.
$wht_rate = $wht_rate_id ? whtRatePercent($pdo, $wht_rate_id) : 0.0;
$wht_amt  = $wht_rate > 0 ? computeWht((float)$amount, $wht_rate) : 0.0;
$wht_acc  = $wht_amt > 0 ? whtPayableAccountId($pdo) : null;
if ($wht_amt > 0 && !$wht_acc) {
    echo json_encode(['success' => false, 'message' => 'WHT was selected but no WHT Payable account is configured. Ask an admin to set it in settings.']);
    exit();
}
if ($wht_amt > 0 && $wht_amt >= (float)$amount) {
    echo json_encode(['success' => false, 'message' => 'Withholding tax cannot meet or exceed the payment amount.']);
    exit();
}

// Phase C — when a PO is referenced, block if that PO's project isn't in user scope.
if ($purchase_order_id) {
    assertScopeForRecord('purchase_orders', 'purchase_order_id', $purchase_order_id);
}

try {
    $pdo->beginTransaction();

    // Generate payment number
    $stmt = $pdo->query("SELECT MAX(payment_id) FROM supplier_payments");
    $next_id = ($stmt->fetchColumn() ?: 0) + 1;
    $payment_number = 'SPY-' . str_pad($next_id, 6, '0', STR_PAD_LEFT);

    // Insert payment record
    $stmt = $pdo->prepare("
        INSERT INTO supplier_payments (
            payment_number, supplier_id, purchase_order_id, payment_date,
            amount, currency, payment_method, paid_from_account_id, reference_number, notes,
            wht_rate_id, wht_base, wht_amount, wht_posted,
            status, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, NOW(), NOW())
    ");
    $stmt->execute([
        $payment_number, $supplier_id, $purchase_order_id, $payment_date,
        $amount, $currency, $payment_method, $paid_from_account_id, $reference_number, $notes,
        ($wht_amt > 0 ? $wht_rate_id : null),
        ($wht_amt > 0 ? (float)$amount : null),
        ($wht_amt > 0 ? $wht_amt : null),
        ($wht_amt > 0 ? $wht_amt : null),
        $user_id
    ]);

    $payment_id = $pdo->lastInsertId();

    // Update purchase order if provided
    if ($purchase_order_id) {
        $stmt = $pdo->prepare("
            UPDATE purchase_orders
            SET paid_amount = COALESCE(paid_amount, 0) + ?,
                payment_status = CASE
                    WHEN COALESCE(paid_amount, 0) + ? >= total_amount THEN 'paid'
                    WHEN COALESCE(paid_amount, 0) + ? > 0 THEN 'partially_paid'
                    ELSE 'unpaid'
                END
            WHERE purchase_order_id = ?
        ");
        $stmt->execute([$amount, $amount, $amount, $purchase_order_id]);
    }

    // Phase 4.8 — auto-post to canonical ledger via journal_mappings.
    // Supplier payment = cash leaves the company to clear the AP raised at
    // GRN approval (Phase 4.7). Standard treatment: Dr Accounts Payable
    // (debt reduced) / Cr Cash (cash reduced).
    //
    // project_id is resolved from the linked PO when present; supplier_payments
    // table itself has no project_id column. Falls back to NULL (company-wide)
    // when payment is not tied to a specific PO.
    // Quiet no-op while 'supplier_payment' mapping is_active=0 (default).
    $resolved_project_id = null;
    if ($purchase_order_id) {
        $proj_stmt = $pdo->prepare("SELECT project_id FROM purchase_orders WHERE purchase_order_id = ?");
        $proj_stmt->execute([$purchase_order_id]);
        $pj = $proj_stmt->fetchColumn();
        if ($pj !== false && $pj !== null) $resolved_project_id = (int)$pj;
    }

    $post_result = autoPostEvent(
        $pdo,
        'supplier_payment',
        'supplier_payment',
        (int)$payment_id,
        (float)$amount,
        $resolved_project_id,
        $payment_date,
        (int)$user_id,
        "Supplier payment {$payment_number} to supplier #{$supplier_id}"
            . ($purchase_order_id ? " (PO #{$purchase_order_id})" : '')
    );

    // Resolve the supplier name so the consolidated report shows who was paid.
    $supName = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
    $supName->execute([$supplier_id]);
    $sup_name = $supName->fetchColumn() ?: "supplier #{$supplier_id}";

    // Consolidated outflow: Dr Accounts Payable, Cr the Paid-From cash/bank
    // account, into the central transactions ledger. Stored on transaction_id
    // so a later delete can reverse it.
    // MONEY-SAFETY (Step 6): I3 "warn but allow" — note a short balance, never block.
    $funds_warn = accountFundsWarning($pdo, (int)$paid_from_account_id, (float)$amount);

    // FAIL LOUDLY: post Dr Accounts Payable / Cr Paid-From with the real reason on
    // failure, so a supplier payment can never save without its ledger entry. The
    // whole payment rolls back (transaction) rather than recording money off-book.
    $outflow_txn = postOutflowOrFail(
        $pdo, 'supplier_payment', $paid_from_account_id, defaultPayableAccountId($pdo),
        (float)$amount, $payment_date, $payment_number,
        "Supplier payment {$payment_number} — {$sup_name}", $resolved_project_id,
        $wht_amt, $wht_acc
    );
    $pdo->prepare("UPDATE supplier_payments SET transaction_id = ? WHERE payment_id = ?")
        ->execute([$outflow_txn, $payment_id]);

    $pdo->commit();

    $log_detail = "Payment: $payment_number, Supplier ID: $supplier_id, Amount: $amount $currency"
                . ($purchase_order_id ? ", PO ID: $purchase_order_id" : "");
    if (!empty($post_result['posted'])) {
        $log_detail .= ", journal entry #{$post_result['entry_id']}";
    } elseif (($post_result['reason'] ?? '') === 'already_posted') {
        $log_detail .= ", already in ledger as entry #{$post_result['existing_entry_id']}";
    }
    logActivity($pdo, $user_id, "Recorded Supplier Payment", $log_detail);

    // The consolidated outflow above always posts (or the whole payment rolled back),
    // so report the real transaction id and any "warn but allow" funds note.
    $msg = 'Payment recorded successfully';
    if ($funds_warn) $msg .= ' ' . $funds_warn;
    $response = ['success' => true, 'message' => $msg, 'payment_id' => $payment_id,
                 'journal_entry_id' => $outflow_txn, 'funds_warning' => $funds_warn];
    echo json_encode($response);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
