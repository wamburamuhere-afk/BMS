<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';   // reverse accrual / payment journals
require_once __DIR__ . '/../core/payroll_tax.php';      // refresh schedule + SDL

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canDelete('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete payroll records']);
    exit();
}

$payroll_id  = $_POST['payroll_id']  ?? null;
$void_reason = trim($_POST['void_reason'] ?? 'Voided by user');

if (!$payroll_id) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID required']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
}

try {
    // Check if record exists
    $stmt = $pdo->prepare("SELECT payroll_number, payroll_period, payment_status, accrual_transaction_id, payment_transaction_id FROM payroll WHERE payroll_id = ?");
    $stmt->execute([$payroll_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payroll) {
        throw new Exception('Payroll record not found');
    }

    if (($payroll['payment_status'] ?? '') === 'voided') {
        throw new Exception('This payroll record is already voided.');
    }

    // Reverse all GL entries (accrual + payment) before marking voided.
    // reversePayrollGl handles both the canonical P-6 path and the legacy path.
    reversePayrollGl($pdo, (int)$payroll_id, (int)$_SESSION['user_id'], $payroll);

    // Mark voided — keep the row for audit and statutory history.
    $pdo->prepare("UPDATE payroll
                      SET payment_status = 'voided',
                          status         = 'voided',
                          voided_by      = ?,
                          voided_at      = NOW(),
                          void_reason    = ?
                    WHERE payroll_id = ?")
        ->execute([(int)$_SESSION['user_id'], $void_reason, (int)$payroll_id]);

    // Period totals changed — refresh the remittance schedule + SDL accrual.
    if (!empty($payroll['payroll_period'])) {
        try {
            $rs = syncStatutoryRemittances($pdo, $payroll['payroll_period'], (int)$_SESSION['user_id']);
            postSdlAccrual($pdo, $payroll['payroll_period'], (float)($rs['amounts']['sdl'] ?? 0), (int)$_SESSION['user_id']);
        } catch (Throwable $e) { error_log('void statutory refresh: ' . $e->getMessage()); }
    }

    // Audit trail (rich) + Activity Log feed. A void is a Delete in audit terms.
    logAudit($pdo, $_SESSION['user_id'], 'void_payroll', [
        'activity_type' => 'void',
        'entity_type'   => 'payroll',
        'entity_id'     => $payroll_id,
        'description'   => "Voided payroll #" . $payroll['payroll_number'] . " — {$void_reason}",
    ]);
    logActivity($pdo, $_SESSION['user_id'], 'Delete payroll',
        "deleted (voided) payroll #{$payroll['payroll_number']} with id {$payroll_id} — {$void_reason}");

    echo json_encode(['success' => true, 'message' => 'Payroll record voided and GL reversed. Record preserved for audit.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
