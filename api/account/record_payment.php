<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/auto_post_hook.php';
require_once __DIR__ . '/../../core/wht.php';

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
    $wht_rate_id              = !empty($_POST['wht_rate_id']) ? (int)$_POST['wht_rate_id'] : null;
    $received_into_account_id = !empty($_POST['received_into_account_id']) ? (int)$_POST['received_into_account_id'] : null;

    if ($invoice_id <= 0 || $amount <= 0) {
        throw new Exception("Invalid invoice ID or amount.");
    }

    // Phase C — block payment recording against invoices on projects not in user scope
    assertScopeForRecord('invoices', 'invoice_id', $invoice_id);
    
    // Check if invoice exists
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception("Invoice not found.");
    }

    if (!in_array($invoice['status'], ['approved', 'partial'], true)) {
        throw new Exception("Payment cannot be recorded — invoice must be approved before payment.");
    }

    // Sales-side WHT (the customer withheld it from us → a receivable / tax credit).
    // Computed on the VAT-exclusive base (invoice subtotal), proportional to the
    // amount being settled so partial payments withhold proportionally.
    $wht_rate = $wht_rate_id ? whtRatePercent($pdo, $wht_rate_id) : 0.0;
    $grand    = (float)($invoice['grand_total'] ?? 0);
    $sub      = (float)($invoice['subtotal'] ?? 0);
    $prop     = ($grand > 0) ? min(1.0, $amount / $grand) : 1.0;
    $wht_base = round(($sub > 0 ? $sub : $amount) * $prop, 2);
    $wht_amt  = $wht_rate > 0 ? computeWht($wht_base, $wht_rate) : 0.0;
    $wht_acc  = $wht_amt > 0 ? whtReceivableAccountId($pdo) : null;
    if ($wht_amt > 0 && !$wht_acc) {
        throw new Exception("WHT was selected but no WHT Receivable account is configured. Ask an admin to set it in settings.");
    }
    if ($wht_amt > 0 && $wht_amt >= $amount) {
        throw new Exception("Withholding tax cannot meet or exceed the amount being settled.");
    }

    $pdo->beginTransaction();

    // Generate unique payment_number (Fix for Duplicate entry error)
    $stmtNum = $pdo->query("SELECT MAX(payment_id) FROM payments");
    $max_id = $stmtNum->fetchColumn() ?: 0;
    $payment_number = 'PAY-' . date('Ymd') . '-' . str_pad(($max_id + 1), 4, '0', STR_PAD_LEFT);

    // Insert Payment
    $stmt = $pdo->prepare("
        INSERT INTO payments (
            payment_number, invoice_id, customer_id, payment_date, amount, currency,
            payment_method, received_into_account_id, reference_number, notes, status,
            wht_rate_id, wht_base, wht_amount, wht_posted, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $payment_number,
        $invoice_id,
        $invoice['customer_id'],
        $payment_date,
        $amount,
        $invoice['currency'],
        $payment_method,
        $received_into_account_id,
        $reference,
        $notes,
        $status,
        ($wht_amt > 0 ? $wht_rate_id : null),
        ($wht_amt > 0 ? $wht_base   : null),
        ($wht_amt > 0 ? $wht_amt    : null),
        ($wht_amt > 0 ? $wht_amt    : null),
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

    // Handle Attachments (Sprint 3)
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $upload_dir = __DIR__ . '/../../uploads/finance/invoices/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $attachment_names = $_POST['attachment_names'] ?? [];

        for ($i = 0; $i < count($_FILES['attachments']['name']); $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['attachments']['tmp_name'][$i];
                $original_name = $_FILES['attachments']['name'][$i];
                $extension = pathinfo($original_name, PATHINFO_EXTENSION);

                // Professional naming: PAY_{ID}_{TIMESTAMP}_{INDEX}.ext
                $file_name = 'PAY_' . $payment_id . '_' . time() . '_' . $i . '.' . $extension;
                $file_path = 'uploads/finance/invoices/' . $file_name;
                $dest_path = $upload_dir . $file_name;

                if (move_uploaded_file($tmp_name, $dest_path)) {
                    $doc_name = !empty($attachment_names[$i]) ? $attachment_names[$i] : $original_name;
                    
                    $attStmt = $pdo->prepare("
                        INSERT INTO payment_attachments (
                            payment_id, file_name, file_path, file_type, file_size, 
                            uploaded_by, uploaded_at, description
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    $attStmt->execute([
                        $payment_id, $doc_name, $file_path, 
                        $_FILES['attachments']['type'][$i], $_FILES['attachments']['size'][$i],
                        $user_id, $doc_name
                    ]);
                }
            }
        }
    }

    // Phase 4.4 — auto-post to canonical ledger via journal_mappings.
    // ONLY for completed payments. A 'pending' payment hasn't actually cleared
    // yet, so we wait for it to be marked completed before touching the ledger.
    // Quiet no-op while 'payment_received' mapping is_active=0 (default).
    $post_result = ['posted' => false, 'reason' => 'status_not_completed'];
    if ($status === 'completed') {
        $post_result = autoPostEvent(
            $pdo,
            'payment_received',
            'payment',
            (int)$payment_id,
            (float)$amount,
            $invoice['project_id'] !== null ? (int)$invoice['project_id'] : null,
            $payment_date,
            (int)$user_id,
            "Payment {$payment_number} received against Invoice #{$invoice['invoice_number']}"
        );
    }

    $pdo->commit();

    // Log activity
    require_once __DIR__ . '/../../helpers.php';
    $log_note = "Recorded Payment: $reference (Amount: " . number_format($amount, 2) . ") for Invoice #$invoice_id";
    if (!empty($post_result['posted'])) {
        $log_note .= " (journal entry #{$post_result['entry_id']})";
    } elseif (($post_result['reason'] ?? '') === 'already_posted') {
        $log_note .= " (already in ledger as entry #{$post_result['existing_entry_id']})";
    }
    logActivity($pdo, $_SESSION['user_id'], $log_note);

    $response = ['success' => true, 'message' => 'Payment recorded successfully', 'payment_id' => $payment_id];
    if (!empty($post_result['posted'])) {
        $response['journal_entry_id'] = $post_result['entry_id'];
    } elseif (($post_result['reason'] ?? '') === 'mapping_not_configured') {
        $response['ledger_warning'] = "Payment recorded, but no ledger entry was created — admin has not "
                                    . "set both Dr/Cr accounts for 'payment_received' in Journal Mappings.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error recording payment: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
