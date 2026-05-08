<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$payment_id = $_POST['payment_id'] ?? '';

if (empty($payment_id)) {
    echo json_encode(['success' => false, 'message' => 'Payment ID is required']);
    exit();
}

try {
    $pdo->beginTransaction();

    // Get payment details before deleting to update PO
    $stmt = $pdo->prepare("SELECT purchase_order_id, amount FROM supplier_payments WHERE payment_id = ?");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
        // Rollback PO paid amount if linked
        if ($payment['purchase_order_id']) {
            $stmt = $pdo->prepare("
                UPDATE purchase_orders 
                SET paid_amount = GREATEST(0, COALESCE(paid_amount, 0) - ?),
                    payment_status = CASE 
                        WHEN GREATEST(0, COALESCE(paid_amount, 0) - ?) >= total_amount THEN 'paid'
                        WHEN GREATEST(0, COALESCE(paid_amount, 0) - ?) > 0 THEN 'partially_paid'
                        ELSE 'unpaid'
                    END
                WHERE purchase_order_id = ?
            ");
            $stmt->execute([$payment['amount'], $payment['amount'], $payment['amount'], $payment['purchase_order_id']]);
        }

        // Delete the payment
        $stmt = $pdo->prepare("DELETE FROM supplier_payments WHERE payment_id = ?");
        $stmt->execute([$payment_id]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Payment deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
