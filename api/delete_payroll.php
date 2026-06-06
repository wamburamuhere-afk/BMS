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
    // Check if record exists
    $stmt = $pdo->prepare("SELECT payroll_number, payroll_period, accrual_transaction_id, payment_transaction_id FROM payroll WHERE payroll_id = ?");
    $stmt->execute([$payroll_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payroll) {
        throw new Exception('Payroll record not found');
    }

    // Accrual model — unwind the ledger before removing the record: reverse the
    // payment (Dr Salaries Payable / Cr Bank) and the accrual (Dr Salaries Expense /
    // Cr PAYE + NSSF + Salaries Payable), restoring all account balances.
    if (!empty($payroll['payment_transaction_id'])) reverseJournalBalances($pdo, (int)$payroll['payment_transaction_id']);
    if (!empty($payroll['accrual_transaction_id'])) reverseJournalBalances($pdo, (int)$payroll['accrual_transaction_id']);

    $stmt = $pdo->prepare("DELETE FROM payroll WHERE payroll_id = ?");
    $stmt->execute([$payroll_id]);

    // The period's totals changed — refresh the remittance schedule + SDL accrual.
    if (!empty($payroll['payroll_period'])) {
        try {
            $rs = syncStatutoryRemittances($pdo, $payroll['payroll_period'], (int)$_SESSION['user_id']);
            postSdlAccrual($pdo, $payroll['payroll_period'], (float)($rs['amounts']['sdl'] ?? 0), (int)$_SESSION['user_id']);
        } catch (Throwable $e) { error_log('delete statutory refresh: ' . $e->getMessage()); }
    }

    // Log delete action
    logAudit($pdo, $_SESSION['user_id'], 'delete_payroll', [
        'activity_type' => 'delete',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Deleted payroll record #" . $payroll['payroll_number'] . " (ID: $payroll_id)"
    ]);

    echo json_encode(['success' => true, 'message' => 'Payroll record deleted successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
