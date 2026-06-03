<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/auto_post_hook.php';
require_once __DIR__ . '/../core/payment_source.php';
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

if (empty($supplier_id) || empty($amount) || empty($payment_method)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
    exit();
}
if (empty($paid_from_account_id)) {
    echo json_encode(['success' => false, 'message' => 'Please choose the account the payment was made from (Paid From)']);
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
            status, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, NOW(), NOW())
    ");
    $stmt->execute([
        $payment_number, $supplier_id, $purchase_order_id, $payment_date,
        $amount, $currency, $payment_method, $paid_from_account_id, $reference_number, $notes,
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
    $outflow_txn = postOutflow(
        $pdo, 'supplier_payment', $paid_from_account_id, defaultPayableAccountId($pdo),
        (float)$amount, $payment_date, $payment_number,
        "Supplier payment {$payment_number} — {$sup_name}", $resolved_project_id
    );
    if ($outflow_txn) {
        $pdo->prepare("UPDATE supplier_payments SET transaction_id = ? WHERE payment_id = ?")
            ->execute([$outflow_txn, $payment_id]);
    }

    $pdo->commit();

    $log_detail = "Payment: $payment_number, Supplier ID: $supplier_id, Amount: $amount $currency"
                . ($purchase_order_id ? ", PO ID: $purchase_order_id" : "");
    if (!empty($post_result['posted'])) {
        $log_detail .= ", journal entry #{$post_result['entry_id']}";
    } elseif (($post_result['reason'] ?? '') === 'already_posted') {
        $log_detail .= ", already in ledger as entry #{$post_result['existing_entry_id']}";
    }
    logActivity($pdo, $user_id, "Recorded Supplier Payment", $log_detail);

    $response = ['success' => true, 'message' => 'Payment recorded successfully', 'payment_id' => $payment_id];
    if (!empty($post_result['posted'])) {
        $response['journal_entry_id'] = $post_result['entry_id'];
    } elseif (($post_result['reason'] ?? '') === 'mapping_not_configured') {
        $response['ledger_warning'] = "Supplier payment recorded, but no ledger entry was created — admin has not "
                                    . "set both Dr/Cr accounts for 'supplier_payment' in Journal Mappings.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
