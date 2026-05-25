<?php
// File: api/update_payroll.php
require_once __DIR__ . '/../roots.php';

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
    $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed') DEFAULT 'pending'");
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

    // Calculate gross and net
    $basic_salary = floatval($_POST['basic_salary'] ?? 0);
    $allowances = floatval($_POST['allowances'] ?? 0);
    $deductions = floatval($_POST['deductions'] ?? 0);
    $tax_amount = floatval($_POST['tax_amount'] ?? 0);
    
    $gross_salary = $basic_salary + $allowances;
    $net_salary = $gross_salary - ($deductions + $tax_amount);

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
        $_POST['payment_status'] ?? 'pending',
        $_POST['notes'] ?? '',
        $payroll_id
    ]);

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
