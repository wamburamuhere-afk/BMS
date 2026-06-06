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
        $where_extra = " AND payment_status IN ('approved', 'processing')";
    } elseif ($status === 'approved') {
        $where_extra = " AND payment_status IN ('pending', 'processing')";
    } elseif ($status === 'processing') {
        $where_extra = " AND payment_status IN ('pending', 'rejected')";
    }

    // For 'paid', capture which records are actually transitioning (so we post
    // exactly one outflow per newly-paid payroll).
    $to_pay = [];
    if ($status === 'paid') {
        $sel = $pdo->prepare("SELECT payroll_id, payroll_period, payroll_date, payroll_number,
                                     gross_salary, tax_amount, nssf_employee, deductions, net_salary
                                FROM payroll
                               WHERE payroll_id IN ($placeholders) AND payment_status IN ('approved','processing')");
        $sel->execute($payroll_ids);
        $to_pay = $sel->fetchAll(PDO::FETCH_ASSOC);
    }

    $sql = "UPDATE payroll SET
                payment_status = ?,
                updated_at = NOW(),
                payment_date = CASE WHEN ? = 'paid' THEN NOW() ELSE payment_date END" .
                ($status === 'paid' ? ", paid_from_account_id = " . (int)$paid_from_account_id : "") . "
            WHERE payroll_id IN ($placeholders) $where_extra";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$status, $status], $payroll_ids));
    $rowCount = $stmt->rowCount();

    // Compound settlement per newly-paid payslip:
    //   Dr Salaries Expense (gross) / Cr PAYE Payable / Cr NSSF Payable / Cr Bank (net).
    // The withheld PAYE/NSSF become liabilities until remitted, so the P&L, Balance
    // Sheet and cash flow are all correct (falls back to net-only if accounts unmapped).
    $affected_periods = [];
    foreach ($to_pay as $p) {
        if ((float)$p['net_salary'] <= 0) continue;
        $txn = postPayrollPayment($pdo, $p, (int)$paid_from_account_id, (int)$_SESSION['user_id']);
        if ($txn) {
            $pdo->prepare("UPDATE payroll SET payment_transaction_id = ? WHERE payroll_id = ?")
                ->execute([$txn, $p['payroll_id']]);
        }
        if (!empty($p['payroll_period'])) $affected_periods[$p['payroll_period']] = true;
    }
    // Keep the remittance schedule in step with what was just paid.
    foreach (array_keys($affected_periods) as $per) {
        try { syncStatutoryRemittances($pdo, $per, (int)$_SESSION['user_id']); }
        catch (Throwable $e) { error_log('syncStatutoryRemittances: ' . $e->getMessage()); }
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
