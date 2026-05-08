<?php
// File: api/duplicate_payroll.php
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $payroll_id = $_POST['payroll_id'] ?? null;
    if (!$payroll_id) {
        throw new Exception('Payroll ID is required');
    }

    // Get original record
    $stmt = $pdo->prepare("SELECT * FROM payroll WHERE payroll_id = ?");
    $stmt->execute([$payroll_id]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payroll) {
        throw new Exception('Original payroll record not found');
    }

    // Generate new payroll number
    $new_payroll_number = 'PAY-' . date('Ym') . '-' . str_pad($payroll['employee_id'], 4, '0', STR_PAD_LEFT) . '-' . time();

    $insert_stmt = $pdo->prepare("
        INSERT INTO payroll (
            payroll_number, employee_id, payroll_period, payroll_date,
            basic_salary, allowances, deductions, tax_amount,
            gross_salary, net_salary, payment_status, payment_method,
            notes, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $insert_stmt->execute([
        $new_payroll_number,
        $payroll['employee_id'],
        $payroll['payroll_period'],
        date('Y-m-d'),
        $payroll['basic_salary'],
        $payroll['allowances'],
        $payroll['deductions'],
        $payroll['tax_amount'],
        $payroll['gross_salary'],
        $payroll['net_salary'],
        'pending',
        $payroll['payment_method'],
        'Duplicated from ' . $payroll['payroll_number'],
        $_SESSION['user_id']
    ]);

    $new_id = $pdo->lastInsertId();

    // Log duplication action
    logAudit($pdo, $_SESSION['user_id'], 'duplicate_payroll', [
        'activity_type' => 'create',
        'entity_type' => 'payroll',
        'entity_id' => $new_id,
        'description' => "Duplicated payroll record #{$payroll['payroll_number']} as #$new_payroll_number"
    ]);

    echo json_encode(['success' => true, 'message' => 'Payroll record duplicated successfully as ' . $new_payroll_number]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
