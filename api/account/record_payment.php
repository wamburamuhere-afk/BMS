<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canEdit('invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to record payments for invoices']);
    exit;
}

try {
    global $pdo;
    
    // Validate inputs
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $reference = $_POST['reference_number'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $status = $_POST['status'] ?? 'completed'; // Default to completed if not set
    $user_id = $_SESSION['user_id'];

    if ($invoice_id <= 0 || $amount <= 0) {
        throw new Exception("Invalid invoice ID or amount.");
    }
    
    // Check if invoice exists
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception("Invoice not found.");
    }

    $pdo->beginTransaction();

    // Insert Payment
    $stmt = $pdo->prepare("
        INSERT INTO payments (
            invoice_id, customer_id, payment_date, amount, currency,
            payment_method, reference_number, notes, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $invoice_id,
        $invoice['customer_id'],
        $payment_date,
        $amount,
        $invoice['currency'],
        $payment_method,
        $reference,
        $notes,
        $status,
        $user_id
    ]);
    
    $payment_id = $pdo->lastInsertId();

    // Update Invoice Status ONLY if payment is completed
    if ($status === 'completed') {
        // Calculate new paid total
        $check_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = 'completed'");
        $check_stmt->execute([$invoice_id]);
        $total_paid = $check_stmt->fetchColumn();
        
        // Determine new status
        $grand_total = floatval($invoice['grand_total']);
        $new_invoice_status = 'partial';
        
        if ($total_paid >= $grand_total - 0.01) { // Float tolerance
            $new_invoice_status = 'paid';
        }
        
        // Update Invoice
        $update_stmt = $pdo->prepare("
            UPDATE invoices 
            SET paid_amount = ?, 
                balance_due = grand_total - ?, 
                status = ?, 
                payment_date = ? 
            WHERE invoice_id = ?
        ");
        $update_stmt->execute([
            $total_paid, 
            $total_paid, 
            $new_invoice_status, 
            $payment_date, 
            $invoice_id
        ]);
    }

    $pdo->commit();

    // Log activity
    require_once __DIR__ . '/../../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], "Recorded Payment: $reference (Amount: " . number_format($amount, 2) . ") for Invoice #$invoice_id");

    echo json_encode(['success' => true, 'message' => 'Payment recorded successfully', 'payment_id' => $payment_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error recording payment: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
