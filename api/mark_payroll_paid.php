<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to mark payroll as paid']);
    exit();
}

$payroll_id           = intval($_POST['payroll_id'] ?? 0);
$paid_from_account_id = intval($_POST['paid_from_account_id'] ?? 0);

if (!$payroll_id) {
    echo json_encode(['success' => false, 'message' => 'Payroll ID required']);
    exit();
}
if (!$paid_from_account_id) {
    echo json_encode(['success' => false, 'message' => 'Paid From account is required']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
}

try {
    $stmt = $pdo->prepare("SELECT * FROM payroll WHERE payroll_id = ? AND payment_status IN ('approved','processing')");
    $stmt->execute([$payroll_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payroll) {
        echo json_encode(['success' => false, 'message' => 'Payroll record not found or not in Approved/Processing state.']);
        exit();
    }

    $pdo->beginTransaction();

    // Defensive accrual — ensure liability is booked before we clear it
    ensurePayrollAccrued($pdo, $payroll_id, (int)$_SESSION['user_id']);

    // GL: Dr Salaries Payable / Cr Bank (clears the liability, reduces cash)
    $txn = postPayrollPayment($pdo, $payroll, $paid_from_account_id, (int)$_SESSION['user_id']);
    if (!$txn) {
        $pdo->rollBack();
        echo json_encode(['success' => false,
            'message' => 'GL payment entry could not be posted — check Salaries Payable and Bank account configuration in System Settings.']);
        exit();
    }

    $pdo->prepare("
        UPDATE payroll
           SET payment_status       = 'paid',
               payment_date         = NOW(),
               payment_transaction_id = ?,
               paid_from_account_id = ?,
               updated_at           = NOW()
         WHERE payroll_id = ?
    ")->execute([$txn, $paid_from_account_id, $payroll_id]);

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Marked Payroll Paid", "Payroll ID: $payroll_id, GL txn: $txn");
    echo json_encode(['success' => true, 'message' => 'Payroll marked as paid and GL updated.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
