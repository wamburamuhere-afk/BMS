<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('supplier_payments')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$payment_id        = intval($_POST['payment_id'] ?? 0);
$supplier_id       = intval($_POST['supplier_id'] ?? 0);
$purchase_order_id = !empty($_POST['purchase_order_id']) ? intval($_POST['purchase_order_id']) : null;
$payment_date      = trim($_POST['payment_date'] ?? '');
$amount            = floatval($_POST['amount'] ?? 0);
$currency          = trim($_POST['currency'] ?? 'TZS');
$payment_method    = trim($_POST['payment_method'] ?? '');
$reference_number  = trim($_POST['reference_number'] ?? '');
$notes             = trim($_POST['notes'] ?? '');
$paid_from_account_id = !empty($_POST['paid_from_account_id']) ? (int)$_POST['paid_from_account_id'] : null;

if (!$payment_id || !$supplier_id || empty($payment_date) || $amount <= 0 || empty($payment_method)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
    exit;
}
if (empty($paid_from_account_id)) {
    echo json_encode(['success' => false, 'message' => 'Please choose the account the payment was made from (Paid From)']);
    exit;
}

// Phase E — project-scope gate via supplier's project_id
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('suppliers', 'supplier_id', $supplier_id);
}

try {
    $stmt = $pdo->prepare("SELECT * FROM supplier_payments WHERE payment_id = ?");
    $stmt->execute([$payment_id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old) {
        echo json_encode(['success' => false, 'message' => 'Payment not found']);
        exit;
    }

    $pdo->beginTransaction();

    // Reverse old PO paid amount
    if ($old['purchase_order_id']) {
        $pdo->prepare("
            UPDATE purchase_orders
            SET paid_amount = GREATEST(0, COALESCE(paid_amount, 0) - ?),
                payment_status = CASE
                    WHEN GREATEST(0, COALESCE(paid_amount, 0) - ?) >= total_amount THEN 'paid'
                    WHEN GREATEST(0, COALESCE(paid_amount, 0) - ?) > 0 THEN 'partially_paid'
                    ELSE 'unpaid' END
            WHERE purchase_order_id = ?
        ")->execute([$old['amount'], $old['amount'], $old['amount'], $old['purchase_order_id']]);
    }

    // Re-sync the consolidated outflow: reverse the old entry, post a fresh one.
    if (!empty($old['transaction_id'])) {
        reverseOutflow($pdo, (int)$old['transaction_id']);
    }
    $resolved_project_id = null;
    if ($purchase_order_id) {
        $pj = $pdo->prepare("SELECT project_id FROM purchase_orders WHERE purchase_order_id = ?");
        $pj->execute([$purchase_order_id]);
        $v = $pj->fetchColumn();
        if ($v !== false && $v !== null) $resolved_project_id = (int)$v;
    }
    $new_txn = postOutflow(
        $pdo, 'supplier_payment', $paid_from_account_id, defaultPayableAccountId($pdo),
        (float)$amount, $payment_date, $old['payment_number'],
        "Supplier payment {$old['payment_number']} to supplier #{$supplier_id}", $resolved_project_id
    );

    // Update payment record
    $pdo->prepare("
        UPDATE supplier_payments SET
            supplier_id = ?, purchase_order_id = ?, payment_date = ?,
            amount = ?, currency = ?, payment_method = ?, paid_from_account_id = ?,
            reference_number = ?, notes = ?, transaction_id = ?, updated_at = NOW()
        WHERE payment_id = ?
    ")->execute([
        $supplier_id, $purchase_order_id, $payment_date,
        $amount, $currency, $payment_method, $paid_from_account_id,
        $reference_number, $notes, $new_txn, $payment_id
    ]);

    // Apply new PO paid amount
    if ($purchase_order_id) {
        $pdo->prepare("
            UPDATE purchase_orders
            SET paid_amount = COALESCE(paid_amount, 0) + ?,
                payment_status = CASE
                    WHEN COALESCE(paid_amount, 0) + ? >= total_amount THEN 'paid'
                    WHEN COALESCE(paid_amount, 0) + ? > 0 THEN 'partially_paid'
                    ELSE 'unpaid' END
            WHERE purchase_order_id = ?
        ")->execute([$amount, $amount, $amount, $purchase_order_id]);
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Updated supplier payment #{$old['payment_number']} — {$currency} {$amount}");

    echo json_encode(['success' => true, 'message' => 'Payment updated successfully']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("update_supplier_payment: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
