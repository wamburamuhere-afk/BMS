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
    $stmt = $pdo->prepare("UPDATE payroll SET payment_status = 'approved' WHERE payroll_id = ?");
    $stmt->execute([$payroll_id]);

    // Accrual model — book the liabilities on approval (Dr Salaries Expense /
    // Cr PAYE + NSSF + Salaries Payable), then refresh the period schedule + SDL.
    try {
        ensurePayrollAccrued($pdo, (int)$payroll_id, (int)$_SESSION['user_id']);
        $per = $pdo->query("SELECT payroll_period FROM payroll WHERE payroll_id = " . (int)$payroll_id)->fetchColumn();
        if ($per) {
            $rs = syncStatutoryRemittances($pdo, $per, (int)$_SESSION['user_id']);
            postSdlAccrual($pdo, $per, (float)($rs['amounts']['sdl'] ?? 0), (int)$_SESSION['user_id']);
        }
    } catch (Throwable $e) { error_log('approve accrual: ' . $e->getMessage()); }

    // Log approval action
    logAudit($pdo, $_SESSION['user_id'], 'approve_payroll', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Approved payroll record ID: $payroll_id"
    ]);

    echo json_encode(['success' => true, 'message' => 'Payroll approved successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
