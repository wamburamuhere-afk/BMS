<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canCreate('supplier_payments')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to add sub-contractor payments']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$supplier_id     = intval($_POST['supplier_id'] ?? 0);
$project_id      = intval($_POST['project_id'] ?? 0);
$payment_date    = $_POST['payment_date'] ?? date('Y-m-d');
$amount          = floatval($_POST['amount'] ?? 0);
$currency        = $_POST['currency'] ?? 'TZS';
$payment_method  = $_POST['payment_method'] ?? '';
$reference_number = trim($_POST['reference_number'] ?? '');
$receipt_number  = trim($_POST['receipt_number'] ?? '');
$notes           = trim($_POST['notes'] ?? '');
$paid_from_account_id = !empty($_POST['paid_from_account_id']) ? (int)$_POST['paid_from_account_id'] : null;

if (!$supplier_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'supplier_id and project_id are required']);
    exit();
}
if (empty($paid_from_account_id)) {
    echo json_encode(['success' => false, 'message' => 'Please choose the account the payment was made from (Paid From)']);
    exit();
}

// Phase C — block adds against projects not in user scope.
if (!userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope.']);
    exit();
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit();
}
if (empty($payment_method)) {
    echo json_encode(['success' => false, 'message' => 'Payment method is required']);
    exit();
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO sc_payments
            (supplier_id, project_id, payment_date, amount, currency,
             payment_method, paid_from_account_id, reference_number, receipt_number, notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)
    ");
    $stmt->execute([
        $supplier_id, $project_id, $payment_date, $amount, $currency,
        $payment_method, $paid_from_account_id, $reference_number ?: null, $receipt_number ?: null, $notes ?: null,
        $_SESSION['user_id']
    ]);

    $paymentId = $pdo->lastInsertId();

    // Consolidated outflow: Dr Accounts Payable, Cr the Paid-From account.
    $txn = postOutflow(
        $pdo, 'sc_payment', $paid_from_account_id, defaultPayableAccountId($pdo),
        (float)$amount, $payment_date, ($reference_number ?: $receipt_number ?: null),
        "Sub-contractor payment to supplier #{$supplier_id} (project #{$project_id})", $project_id
    );
    if ($txn) {
        $pdo->prepare("UPDATE sc_payments SET transaction_id = ? WHERE id = ?")->execute([$txn, $paymentId]);
    }
    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Recorded Sub-Contractor Payment", "Payment ID: $paymentId, Supplier ID: $supplier_id, Project ID: $project_id, Amount: $amount $currency");

    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'id' => $paymentId]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
