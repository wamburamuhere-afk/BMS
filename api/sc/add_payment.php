<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/payment_source.php';
require_once __DIR__ . '/../../core/money_guard.php';   // postOutflowOrFail / accountFundsWarning
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

    // Resolve the sub-contractor + project names so the consolidated report shows
    // exactly who was paid (not just an id).
    $scName = $pdo->prepare("SELECT supplier_name FROM sub_contractors WHERE supplier_id = ?");
    $scName->execute([$supplier_id]);
    $sc_name = $scName->fetchColumn() ?: "supplier #{$supplier_id}";
    $pjName = $pdo->prepare("SELECT project_name FROM projects WHERE project_id = ?");
    $pjName->execute([$project_id]);
    $pj_name = $pjName->fetchColumn() ?: "project #{$project_id}";

    // MONEY-SAFETY (Step 7): I3 "warn but allow" — note a short balance, never block.
    $funds_warn = accountFundsWarning($pdo, (int)$paid_from_account_id, (float)$amount);

    // Consolidated outflow: Dr Accounts Payable, Cr the Paid-From account. FAIL LOUDLY —
    // a failed post throws the real reason and the whole payment rolls back rather than
    // saving an off-book sub-contractor payment.
    $txn = postOutflowOrFail(
        $pdo, 'sc_payment', $paid_from_account_id, defaultPayableAccountId($pdo),
        (float)$amount, $payment_date, ($reference_number ?: $receipt_number ?: null),
        "Sub-contractor payment — {$sc_name} ({$pj_name})", $project_id
    );
    $pdo->prepare("UPDATE sc_payments SET transaction_id = ? WHERE id = ?")->execute([$txn, $paymentId]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Recorded Sub-Contractor Payment", "Payment ID: $paymentId, Supplier ID: $supplier_id, Project ID: $project_id, Amount: $amount $currency");

    $msg = 'Payment recorded successfully';
    if ($funds_warn) $msg .= ' ' . $funds_warn;
    echo json_encode(['success' => true, 'message' => $msg, 'id' => $paymentId, 'funds_warning' => $funds_warn]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
