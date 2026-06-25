<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';   // accrual posting
require_once __DIR__ . '/../core/payroll_tax.php';      // statutory schedule + SDL

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canApprove('payroll') && !canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to approve payroll']);
    exit();
}

$payroll_id = $_POST['payroll_id'] ?? null;

if (!$payroll_id) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID required']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE payroll SET payment_status = 'approved' WHERE payroll_id = ?")->execute([$payroll_id]);

    // Accrual model — book the liabilities on approval:
    //   Dr Salaries Expense / Cr PAYE Payable + NSSF Payable + Salaries Payable
    $accrualTxn = null;
    try { $accrualTxn = ensurePayrollAccrued($pdo, (int)$payroll_id, (int)$_SESSION['user_id']); }
    catch (Throwable $e) { error_log('approve accrual: ' . $e->getMessage()); }

    if (!$accrualTxn) {
        $pdo->rollBack();
        echo json_encode(['success' => false,
            'message' => 'GL accrual could not be posted — ensure Salaries Expense, PAYE Payable, NSSF Payable and Salaries Payable accounts are mapped in System Settings, then try again.']);
        exit;
    }

    $pdo->commit();

    // SDL refresh outside the transaction (idempotent; failure must not unwind the approval)
    try {
        $per = $pdo->query("SELECT payroll_period FROM payroll WHERE payroll_id = " . (int)$payroll_id)->fetchColumn();
        if ($per) {
            $rs = syncStatutoryRemittances($pdo, $per, (int)$_SESSION['user_id']);
            postSdlAccrual($pdo, $per, (float)($rs['amounts']['sdl'] ?? 0), (int)$_SESSION['user_id']);
        }
    } catch (Throwable $e) { error_log('sdl sync: ' . $e->getMessage()); }

    logAudit($pdo, $_SESSION['user_id'], 'approve_payroll', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Approved payroll record ID: $payroll_id"
    ]);

    echo json_encode(['success' => true, 'message' => 'Payroll approved and GL accrual posted.']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
