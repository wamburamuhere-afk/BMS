<?php
// File: api/get_payroll.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canView('payroll')) {
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
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
            d.department_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE p.payroll_id = ?
    ");
    $stmt->execute([$payroll_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payroll) {
        echo json_encode(['success' => true, 'data' => $payroll]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Payroll record not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
