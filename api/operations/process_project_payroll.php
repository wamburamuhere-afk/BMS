<?php
// api/operations/process_project_payroll.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $project_id        = intval($_POST['project_id']    ?? 0);
    $payroll_period    = trim($_POST['payroll_period']  ?? '');
    $payroll_date      = trim($_POST['payroll_date']    ?? date('Y-m-d'));
    $include_allowances = isset($_POST['include_allowances']);
    $include_deductions = isset($_POST['include_deductions']);
    $consider_attendance = isset($_POST['consider_attendance']);
    $auto_approve      = isset($_POST['auto_approve']);

    if (!$project_id || !$payroll_period) {
        throw new Exception('Project ID and payroll period are required');
    }

    $parts = explode('-', $payroll_period);
    $year  = intval($parts[0]);
    $month = intval($parts[1]);

    // Get all active staff for this project
    $staff_stmt = $pdo->prepare("
        SELECT e.employee_id, e.employee_number, e.first_name, e.last_name,
               e.basic_salary, e.employment_status
        FROM employees e
        WHERE e.project_id = ? AND e.status != 'terminated'
    ");
    $staff_stmt->execute([$project_id]);
    $staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($staff_list)) {
        throw new Exception('No active staff found for this project');
    }

    $processed = 0;
    $skipped   = 0;
    $status    = $auto_approve ? 'approved' : 'pending';

    foreach ($staff_list as $emp) {
        // Skip if payroll already exists for this period
        $exists = $pdo->prepare("SELECT payroll_id FROM payroll WHERE employee_id = ? AND year = ? AND month = ?");
        $exists->execute([$emp['employee_id'], $year, $month]);
        if ($exists->fetch()) { $skipped++; continue; }

        $basic_salary = floatval($emp['basic_salary'] ?? 0);
        $allowances   = 0;
        $deductions   = 0;
        $tax          = 0;

        if ($include_allowances) {
            $all_stmt = $pdo->prepare("SELECT SUM(amount) FROM employee_allowances WHERE employee_id = ? AND is_active = 1");
            $all_stmt->execute([$emp['employee_id']]);
            $allowances = floatval($all_stmt->fetchColumn());
        }

        if ($include_deductions) {
            $ded_stmt = $pdo->prepare("SELECT SUM(amount) FROM employee_deductions WHERE employee_id = ? AND is_active = 1");
            $ded_stmt->execute([$emp['employee_id']]);
            $deductions = floatval($ded_stmt->fetchColumn());
        }

        if ($consider_attendance) {
            $days_stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND YEAR(attendance_date) = ? AND MONTH(attendance_date) = ? AND status = 'absent'");
            $days_stmt->execute([$emp['employee_id'], $year, $month]);
            $absent_days = intval($days_stmt->fetchColumn());
            $working_days = 26;
            $daily_rate = $basic_salary / $working_days;
            $deductions += $absent_days * $daily_rate;
        }

        $gross_salary = $basic_salary + $allowances;
        $net_salary   = $gross_salary - $deductions - $tax;
        $payroll_no   = 'PR-' . strtoupper(date('yM')) . '-' . $emp['employee_id'];

        $pdo->prepare("
            INSERT INTO payroll (employee_id, payroll_number, payroll_date, basic_salary, total_allowances, total_deductions, tax_amount, gross_salary, net_salary, status, payment_status, year, month, notes, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $emp['employee_id'], $payroll_no, $payroll_date, $basic_salary,
            $allowances, $deductions, $tax, $gross_salary, $net_salary,
            $status, $status, $year, $month, '', $_SESSION['user_id']
        ]);

        $processed++;
    }

    echo json_encode([
        'success'   => true,
        'message'   => "Payroll processed: $processed staff. Skipped (already exists): $skipped.",
        'processed' => $processed,
        'skipped'   => $skipped
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
