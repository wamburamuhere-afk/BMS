<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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

if (!$supplier_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'supplier_id and project_id are required']);
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
    $stmt = $pdo->prepare("
        INSERT INTO sc_payments
            (supplier_id, project_id, payment_date, amount, currency,
             payment_method, reference_number, receipt_number, notes, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)
    ");
    $stmt->execute([
        $supplier_id, $project_id, $payment_date, $amount, $currency,
        $payment_method, $reference_number ?: null, $receipt_number ?: null, $notes ?: null,
        $_SESSION['user_id']
    ]);

    $paymentId = $pdo->lastInsertId();
    logActivity($pdo, $_SESSION['user_id'], "Recorded Sub-Contractor Payment", "Payment ID: $paymentId, Supplier ID: $supplier_id, Project ID: $project_id, Amount: $amount $currency");

    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'id' => $paymentId]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
