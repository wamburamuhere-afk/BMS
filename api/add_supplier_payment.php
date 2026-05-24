<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
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

if (empty($supplier_id) || empty($amount) || empty($payment_method)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields']);
    exit();
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
            amount, currency, payment_method, reference_number, notes, 
            status, created_by, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, NOW(), NOW())
    ");
    $stmt->execute([
        $payment_number, $supplier_id, $purchase_order_id, $payment_date,
        $amount, $currency, $payment_method, $reference_number, $notes,
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

    $pdo->commit();

    logActivity($pdo, $user_id, "Recorded Supplier Payment", "Payment: $payment_number, Supplier ID: $supplier_id, Amount: $amount $currency" . ($purchase_order_id ? ", PO ID: $purchase_order_id" : ""));

    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'payment_id' => $payment_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
