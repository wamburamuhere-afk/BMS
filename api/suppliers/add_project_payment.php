<?php
// scope-audit: skip — write API — create supplier project payment; purchase_order scope enforced by PO gating
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canCreate('received_invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

csrf_check();

$supplier_id       = intval($_POST['supplier_id']       ?? 0);
$project_id        = intval($_POST['project_id']        ?? 0);
$purchase_order_id = intval($_POST['purchase_order_id'] ?? 0);
$payment_date      = $_POST['payment_date']      ?? date('Y-m-d');
$amount            = floatval($_POST['amount']   ?? 0);
$currency          = trim($_POST['currency']     ?? 'TZS');
$payment_method    = trim($_POST['payment_method']    ?? '');
$reference_number  = trim($_POST['reference_number']  ?? '');
$notes             = trim($_POST['notes']              ?? '');

if (!$supplier_id || !$project_id) {
    echo json_encode(['success' => false, 'message' => 'supplier_id and project_id are required']);
    exit;
}
if (!$purchase_order_id) {
    echo json_encode(['success' => false, 'message' => 'Please select a Purchase Order']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than zero']);
    exit;
}
if (empty($payment_method)) {
    echo json_encode(['success' => false, 'message' => 'Payment method is required']);
    exit;
}

try {
    // Verify the PO belongs to this project and this supplier
    $check = $pdo->prepare("
        SELECT purchase_order_id, grand_total, paid_amount, currency
        FROM purchase_orders
        WHERE purchase_order_id = ? AND project_id = ? AND supplier_id = ?
          AND status NOT IN ('cancelled','draft')
    ");
    $check->execute([$purchase_order_id, $project_id, $supplier_id]);
    $po = $check->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        echo json_encode(['success' => false, 'message' => 'Purchase order not found or does not belong to this project/supplier']);
        exit;
    }

    $pdo->beginTransaction();

    // Company-prefixed sequential payment number (BFS-SPY-0001), gap-free.
    require_once __DIR__ . '/../../core/code_generator.php';
    $payment_number = nextCode($pdo, 'SPY');

    // Insert payment
    $pdo->prepare("
        INSERT INTO supplier_payments
            (payment_number, supplier_id, purchase_order_id, payment_date,
             amount, currency, payment_method, reference_number, notes,
             status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
    ")->execute([
        $payment_number, $supplier_id, $purchase_order_id, $payment_date,
        $amount, $currency, $payment_method,
        $reference_number ?: null, $notes ?: null,
        $_SESSION['user_id']
    ]);

    $payment_id = $pdo->lastInsertId();

    // Update PO paid_amount and payment_status
    $newPaid  = floatval($po['paid_amount']) + $amount;
    $grandTotal = floatval($po['grand_total']);
    $payStatus = $newPaid >= $grandTotal ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');

    $pdo->prepare("
        UPDATE purchase_orders
        SET paid_amount = ?, payment_status = ?
        WHERE purchase_order_id = ?
    ")->execute([$newPaid, $payStatus, $purchase_order_id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'],
        "Recorded supplier payment $payment_number: $currency " . number_format($amount, 2) .
        " for PO #$purchase_order_id (project_id=$project_id, supplier_id=$supplier_id)");

    echo json_encode([
        'success'        => true,
        'message'        => 'Payment recorded successfully.',
        'payment_id'     => $payment_id,
        'payment_number' => $payment_number,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('suppliers/add_project_payment: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
