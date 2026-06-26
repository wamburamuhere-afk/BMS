<?php
// File: api/update_payroll.php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';   // accrual reverse / re-post
require_once __DIR__ . '/../core/payroll_tax.php';      // refresh schedule + SDL

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canEdit('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to edit payroll records']);
    exit();
}

try {
    // DB Hardening
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial') DEFAULT 'pending'");
    try { $pdo->exec("ALTER TABLE payroll ADD COLUMN gross_salary DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount"); } catch(Exception $e) {}
} catch (Exception $e) {}

try {
    $payroll_id = $_POST['payroll_id'] ?? null;
    if (!$payroll_id) {
        throw new Exception('Payroll ID is required');
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForEmployeeRecord')) {
        assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
    }

    // Load the existing row (to preserve NSSF, which the edit modal doesn't expose,
    // and to know the accrual/payment state).
    $cur = $pdo->prepare("SELECT * FROM payroll WHERE payroll_id = ?");
    $cur->execute([$payroll_id]);
    $existing = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$existing) { throw new Exception('Payroll record not found'); }

    // Calculate gross and net (net now correctly nets out NSSF as well).
    $basic_salary = floatval($_POST['basic_salary'] ?? 0);
    $allowances = floatval($_POST['allowances'] ?? 0);
    $deductions = floatval($_POST['deductions'] ?? 0);
    $tax_amount = floatval($_POST['tax_amount'] ?? 0);
    $nssf = (float)($existing['nssf_employee'] ?? 0);

    $gross_salary = $basic_salary + $allowances;
    $net_salary = $gross_salary - ($deductions + $nssf + $tax_amount);
    $new_status = $_POST['payment_status'] ?? $existing['payment_status'] ?? 'pending';

    $stmt = $pdo->prepare("
        UPDATE payroll SET
            basic_salary = ?,
            allowances = ?,
            deductions = ?,
            tax_amount = ?,
            gross_salary = ?,
            net_salary = ?,
            payment_method = ?,
            payment_status = ?,
            notes = ?,
            updated_at = NOW()
        WHERE payroll_id = ?
    ");

    $stmt->execute([
        $basic_salary,
        $allowances,
        $deductions,
        $tax_amount,
        $gross_salary,
        $net_salary,
        $_POST['payment_method'] ?? 'bank',
        $new_status,
        $_POST['notes'] ?? '',
        $payroll_id
    ]);

    // Accrual model — if the record was accrued and is NOT yet paid, re-post the
    // accrual to match the edited amounts (a paid record's ledger is left intact).
    if (empty($existing['payment_transaction_id']) && ($existing['payment_status'] ?? '') !== 'paid') {
        if (!empty($existing['accrual_transaction_id'])) {
            reverseJournalBalances($pdo, (int)$existing['accrual_transaction_id']);
            $pdo->prepare("UPDATE payroll SET accrual_transaction_id = NULL WHERE payroll_id = ?")->execute([$payroll_id]);
        }
        if (in_array($new_status, ['approved', 'processing'], true)) {
            try { ensurePayrollAccrued($pdo, (int)$payroll_id, (int)$_SESSION['user_id']); }
            catch (Throwable $e) { error_log('update re-accrual: ' . $e->getMessage()); }
        }
    }
    // Refresh the period's schedule + SDL accrual to match the edit.
    if (!empty($existing['payroll_period'])) {
        try {
            $rs = syncStatutoryRemittances($pdo, $existing['payroll_period'], (int)$_SESSION['user_id']);
            postSdlAccrual($pdo, $existing['payroll_period'], (float)($rs['amounts']['sdl'] ?? 0), (int)$_SESSION['user_id']);
        } catch (Throwable $e) { error_log('update statutory refresh: ' . $e->getMessage()); }
    }

    logActivity($pdo, $_SESSION['user_id'], 'Edit payroll', "User edited payroll record for period: {$existing['payroll_period']} (ID $payroll_id)");

    // Log update action
    logAudit($pdo, $_SESSION['user_id'], 'update_payroll', [
        'activity_type' => 'update',
        'entity_type' => 'payroll',
        'entity_id' => $payroll_id,
        'description' => "Updated payroll record ID: $payroll_id. New Net: " . number_format($net_salary, 2)
    ]);

    echo json_encode(['success' => true, 'message' => 'Payroll record updated successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
