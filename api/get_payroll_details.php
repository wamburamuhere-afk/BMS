<?php
// File: api/get_payroll_details.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $payroll_id = $_GET['id'] ?? null;
    if (!$payroll_id) {
        throw new Exception('Payroll ID is required');
    }

    // Phase D — gate via the employee's project
    if (function_exists('assertScopeForEmployeeRecord')) {
        assertScopeForEmployeeRecord('payroll', 'payroll_id', $payroll_id);
    }

    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.first_name,
            e.last_name,
            e.employee_number,
            e.designation_id,
            e.department_id,
            d.department_name,
            des.designation_name,
            u_c.username as creator_name,
            u_a.username as approver_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN designations des ON e.designation_id = des.designation_id
        LEFT JOIN users u_c ON p.created_by = u_c.user_id
        LEFT JOIN users u_a ON p.approved_by = u_a.user_id
        WHERE p.payroll_id = ?
    ");
    $stmt->execute([$payroll_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payroll) {
        throw new Exception('Payroll record not found');
    }

    // Fetch individual allowances
    $allow_stmt = $pdo->prepare("
        SELECT allowance_type, amount 
        FROM employee_allowances 
        WHERE employee_id = ? AND status = 'active'
    ");
    $allow_stmt->execute([$payroll['employee_id']]);
    $allowances = $allow_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch individual deductions
    $deduct_stmt = $pdo->prepare("
        SELECT deduction_type, amount 
        FROM employee_deductions 
        WHERE employee_id = ? AND status = 'active'
    ");
    $deduct_stmt->execute([$payroll['employee_id']]);
    $deductions = $deduct_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'payroll' => $payroll,
            'allowances' => $allowances,
            'deductions' => $deductions
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
