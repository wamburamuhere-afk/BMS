<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/auto_post_hook.php';
require_once __DIR__ . '/../core/payment_source.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change payroll status']);
    exit();
}

$payroll_id = $_POST['payroll_id'] ?? null;
$status = $_POST['status'] ?? null;
$paid_from_account_id = !empty($_POST['paid_from_account_id']) ? (int)$_POST['paid_from_account_id'] : null;
// Optional partial-payment amount; 0 or absent = pay full remaining balance.
$paid_amount_input = isset($_POST['paid_amount']) && (float)$_POST['paid_amount'] > 0
    ? round((float)$_POST['paid_amount'], 2) : 0.0;

if (!$payroll_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID and status required']);
    exit();
}
// Paying requires a source account (no one-click pay without the payment form).
if ($status === 'paid' && empty($paid_from_account_id)) {
    echo json_encode(['success' => false, 'message' => 'Please choose the account the salary is paid from (Paid From)']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
}

try {
    // DB Hardening
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed') DEFAULT 'pending'");
    try { $pdo->exec("ALTER TABLE payroll ADD COLUMN gross_salary DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount"); } catch(Exception $e) {}
} catch (Exception $e) {}

try {
    // Phase 4.6 — fetch payroll snapshot BEFORE the UPDATE so the auto-post
    // has clean net_salary + payroll_date data. (Payroll has no project_id
    // column; the entry is company-wide overhead.)
    $snap_stmt = $pdo->prepare("SELECT payroll_id, payroll_period, payroll_date, payroll_number,
                                     gross_salary, tax_amount, nssf_employee, deductions,
                                     net_salary, amount_paid, accrual_transaction_id
                               FROM payroll WHERE payroll_id = ?");
    $snap_stmt->execute([$payroll_id]);
    $payroll_snap = $snap_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payroll_snap) throw new Exception('Payroll record not found');

    // Wrap status change + audit + auto-post in one transaction so a ledger
    // posting failure rolls back the status change too.
    $pdo->beginTransaction();

    // Partial-payment — compute actual new status and cumulative amount_paid.
    $thisPayment = 0.0;
    $newAmtPaid  = 0.0;
    $newStatus   = $status;
    if ($status === 'paid') {
        $net       = round((float)$payroll_snap['net_salary'], 2);
        $alreadyPd = round((float)$payroll_snap['amount_paid'], 2);
        $remaining = round($net - $alreadyPd, 2);
        if ($remaining <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'This payroll record is already fully paid.']);
            exit();
        }
        $thisPayment = ($paid_amount_input > 0) ? min($paid_amount_input, $remaining) : $remaining;
        $newAmtPaid  = round($alreadyPd + $thisPayment, 2);
        $newStatus   = ($newAmtPaid >= $net - 0.005) ? 'paid' : 'partial';
    }

    // Additional fields based on status
    $sql = "UPDATE payroll SET payment_status = ?, updated_by = ?, updated_at = NOW()";

    if ($status === 'approved') {
        $sql .= ", approved_by = " . $_SESSION['user_id'] . ", date_approved = NOW()";
    } elseif ($status === 'paid') {
        $sql .= ", amount_paid = ?, payment_date = NOW(), paid_from_account_id = " . (int)$paid_from_account_id;
    }

    $sql .= " WHERE payroll_id = ?";

    $stmt = $pdo->prepare($sql);
    if ($status === 'paid') {
        $stmt->execute([$newStatus, $_SESSION['user_id'], $newAmtPaid, $payroll_id]);
    } else {
        $stmt->execute([$newStatus, $_SESSION['user_id'], $payroll_id]);
    }

    // OUT-4 accrual — recognise the gross salary expense + statutory payables when
    // payroll is APPROVED (Dr Salaries Expense / Cr PAYE/NSSF/Salaries Payable), the
    // same accrual approve_payroll.php books, so this path is accrual basis too.
    // Idempotent + best-effort.
    if ($status === 'approved') {
        try { ensurePayrollAccrued($pdo, (int)$payroll_id, (int)$_SESSION['user_id']); }
        catch (Throwable $e) { error_log("payroll accrual (status update) {$payroll_id}: " . $e->getMessage()); }
    }

    // Settlement on payment: clear the STAFF liability — Dr Salaries Payable /
    // Cr Bank (net) — via postPayrollPayment (which also ensures the accrual exists
    // first). Previously this debited Trade Creditors (AP), which never cleared
    // Salaries Payable. Degrades to an AP outflow only when no Salaries Payable
    // account is configured. Stored on payment_transaction_id.
    if ($status === 'paid' && $thisPayment > 0) {
        $payroll_txn = postPayrollPayment($pdo, $payroll_snap, (int)$paid_from_account_id, (int)$_SESSION['user_id'], $thisPayment);
        if ($payroll_txn) {
            $pdo->prepare("UPDATE payroll SET payment_transaction_id = ? WHERE payroll_id = ?")
                ->execute([$payroll_txn, $payroll_id]);
        }
    }

    // Log status update action
    logAudit($pdo, $_SESSION['user_id'], 'update_payroll_status', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Updated payroll status to '$status' for record ID: $payroll_id"
    ]);

    // Phase 4.6 — auto-post to canonical ledger via journal_mappings.
    // Only the 'paid' transition writes to the ledger. 'approved' is HR
    // signoff (no cash movement yet); only 'paid' moves money.
    // Uses net_salary (what the employee actually receives after tax + deductions).
    // Quiet no-op while 'payroll_paid' mapping is_active=0 (default).
    $post_result = ['posted' => false, 'reason' => 'status_not_paid'];
    if ($status === 'paid' && $thisPayment > 0) {
        $post_result = autoPostEvent(
            $pdo,
            'payroll_paid',
            'payroll',
            (int)$payroll_id,
            $thisPayment,
            null,  // payroll is company-wide; no project_id
            $payroll_snap['payroll_date'],
            (int)$_SESSION['user_id'],
            "Payroll {$payroll_snap['payroll_number']} paid (net)"
        );
    }

    $pdo->commit();

    $response = ['success' => true, 'message' => 'Status updated successfully.'];
    if (!empty($post_result['posted'])) {
        $response['journal_entry_id'] = $post_result['entry_id'];
    } elseif (($post_result['reason'] ?? '') === 'mapping_not_configured') {
        $response['ledger_warning'] = "Payroll marked paid, but no ledger entry was created — admin has not "
                                    . "set both Dr/Cr accounts for 'payroll_paid' in Journal Mappings.";
    }
    echo json_encode($response);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
