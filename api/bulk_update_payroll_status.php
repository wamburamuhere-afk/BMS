<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';
require_once __DIR__ . '/../core/payroll_tax.php';   // syncStatutoryRemittances()

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to bulk update payroll status']);
    exit();
}

// Sanitise to positive integers and drop anything non-numeric. Unprocessed/preview
// rows have no payroll_id, so their checkbox can submit the literal string 'null';
// binding that against the integer payroll_id column raises "Truncated incorrect
// DOUBLE value: 'null'" under strict SQL mode (production), though it is silently
// tolerated on non-strict local servers. Filtering here makes the endpoint correct
// regardless of what the client sends or the server's SQL mode.
$payroll_ids = array_values(array_unique(array_filter(
    array_map('intval', (array)($_POST['payroll_ids'] ?? [])),
    static fn($v) => $v > 0
)));
$status = $_POST['status'] ?? '';
$paid_from_account_id = !empty($_POST['paid_from_account_id']) ? (int)$_POST['paid_from_account_id'] : null;
// Optional partial-payment amount; 0 or absent = pay full remaining balance per record.
$paid_amount_input = isset($_POST['paid_amount']) && (float)$_POST['paid_amount'] > 0
    ? round((float)$_POST['paid_amount'], 2) : 0.0;

if (empty($payroll_ids)) {
    echo json_encode(['success' => false, 'message' => 'No valid payroll records selected. Process the payroll first, then approve.']);
    exit();
}

if (empty($status)) {
    echo json_encode(['success' => false, 'message' => 'Status required']);
    exit();
}
// Paying requires a source account (no one-click pay without the payment form).
if ($status === 'paid' && empty($paid_from_account_id)) {
    echo json_encode(['success' => false, 'message' => 'Please choose the account the salaries are paid from (Paid From)']);
    exit();
}

// Phase D — gate each payroll record against scope
if (function_exists('assertScopeForEmployeeRecord')) {
    foreach ($payroll_ids as $pid) {
        assertScopeForEmployeeRecord('payroll', 'payroll_id', intval($pid));
    }
}

try {
    // DB Hardening: Fix ENUMs and add missing columns
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed') DEFAULT 'pending'");
    try { $pdo->exec("ALTER TABLE payroll ADD COLUMN gross_salary DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount"); } catch(Exception $e) {}
} catch (Exception $e) {}

try {
    $placeholders = str_repeat('?,', count($payroll_ids) - 1) . '?';
    
    // Only allow logical transitions
    $where_extra = "";
    if ($status === 'paid') {
        // 'partial' records can receive follow-up payments
        $where_extra = " AND payment_status IN ('approved', 'processing', 'partial')";
    } elseif ($status === 'approved') {
        $where_extra = " AND payment_status IN ('pending', 'processing')";
    } elseif ($status === 'processing') {
        $where_extra = " AND payment_status IN ('pending', 'rejected')";
    }

    // For 'paid', capture which records are actually transitioning (so we post
    // exactly one outflow per newly-paid/partially-paid payroll).
    $to_pay = [];
    if ($status === 'paid') {
        $sel = $pdo->prepare("SELECT payroll_id, payroll_period, payroll_date, payroll_number,
                                     gross_salary, tax_amount, nssf_employee, deductions,
                                     net_salary, amount_paid, accrual_transaction_id
                                FROM payroll
                               WHERE payroll_id IN ($placeholders)
                                 AND payment_status IN ('approved','processing','partial')");
        $sel->execute($payroll_ids);
        $to_pay = $sel->fetchAll(PDO::FETCH_ASSOC);
    }

    // For 'approved', capture which records are transitioning so each is accrued.
    $to_approve = [];
    if ($status === 'approved') {
        $sel = $pdo->prepare("SELECT payroll_id, payroll_period FROM payroll
                               WHERE payroll_id IN ($placeholders) AND payment_status IN ('pending','processing')");
        $sel->execute($payroll_ids);
        $to_approve = $sel->fetchAll(PDO::FETCH_ASSOC);
    }

    // Partial-payment path: each record is updated individually so status and
    // amount_paid can differ per record. The old bulk UPDATE is kept only for
    // non-payment status changes (approved, processing, cancelled …).
    $affected_periods = [];
    $rowCount = 0;

    if ($status === 'paid') {
        foreach ($to_pay as $p) {
            $net       = round((float)$p['net_salary'], 2);
            $alreadyPd = round((float)$p['amount_paid'], 2);
            $remaining = round($net - $alreadyPd, 2);
            if ($remaining <= 0) continue;   // already fully paid — skip

            // How much to pay this instalment
            $thisPayment = ($paid_amount_input > 0)
                ? min($paid_amount_input, $remaining)   // cap at remaining
                : $remaining;                           // blank = pay all remaining
            $newAmtPaid = round($alreadyPd + $thisPayment, 2);
            $newStatus  = ($newAmtPaid >= $net - 0.005) ? 'paid' : 'partial';

            $pdo->prepare("UPDATE payroll
                              SET payment_status      = ?,
                                  amount_paid         = ?,
                                  paid_from_account_id = ?,
                                  payment_date        = NOW(),
                                  updated_at          = NOW()
                            WHERE payroll_id = ?")
                ->execute([$newStatus, $newAmtPaid, (int)$paid_from_account_id, $p['payroll_id']]);
            $rowCount++;

            // Post GL: Dr Salaries Payable / Cr Bank (for the instalment amount only)
            $txn = postPayrollPayment($pdo, $p, (int)$paid_from_account_id, (int)$_SESSION['user_id'], $thisPayment);
            if ($txn) {
                $pdo->prepare("UPDATE payroll SET payment_transaction_id = ? WHERE payroll_id = ?")
                    ->execute([$txn, $p['payroll_id']]);
            }
            if (!empty($p['payroll_period'])) $affected_periods[$p['payroll_period']] = true;
        }
    } else {
        // Non-payment status changes use the original bulk UPDATE
        $sql = "UPDATE payroll SET
                    payment_status = ?,
                    updated_at = NOW(),
                    payment_date = CASE WHEN ? = 'paid' THEN NOW() ELSE payment_date END
                WHERE payroll_id IN ($placeholders) $where_extra";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$status, $status], $payroll_ids));
        $rowCount = $stmt->rowCount();
    }

    // Accrual on approval — book each newly-approved record's liabilities
    // (Dr Salaries Expense / Cr PAYE + NSSF + Salaries Payable).
    foreach ($to_approve as $p) {
        try { ensurePayrollAccrued($pdo, (int)$p['payroll_id'], (int)$_SESSION['user_id']); }
        catch (Throwable $e) { error_log('approve accrual: ' . $e->getMessage()); }
        if (!empty($p['payroll_period'])) $affected_periods[$p['payroll_period']] = true;
    }

    // Keep the remittance schedule + SDL accrual in step for every affected period.
    foreach (array_keys($affected_periods) as $per) {
        try {
            $rs = syncStatutoryRemittances($pdo, $per, (int)$_SESSION['user_id']);
            postSdlAccrual($pdo, $per, (float)($rs['amounts']['sdl'] ?? 0), (int)$_SESSION['user_id']);
        } catch (Throwable $e) { error_log('statutory sync/accrual: ' . $e->getMessage()); }
    }

    // Log bulk update action
    logAudit($pdo, $_SESSION['user_id'], 'bulk_update_payroll_status', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'description' => "Updated status to '$status' for $rowCount payroll records."
    ]);
    
    echo json_encode(['success' => true, 'message' => "Bulk status update completed. $rowCount records updated successfully."]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
