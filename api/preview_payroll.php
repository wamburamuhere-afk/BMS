<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/salary_structure.php';     // Plan H1 — component expansion
require_once __DIR__ . '/../core/attendance_payroll.php';   // Plan H2 — attendance-driven payroll
require_once __DIR__ . '/../core/leave_balance.php';        // Plan H3 — unpaid-leave deduction
require_once __DIR__ . '/../core/payroll_tax.php';          // Statutory engine — PAYE (on gross−NSSF), NSSF, SDL

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// DEBUG: Log session data
error_log("Preview Payroll - Session Data: " . print_r($_SESSION, true));
error_log("Preview Payroll - user_role: " . ($user_role ?? 'NOT SET'));

// Check permissions
$user_role = $_SESSION['user_role'] ?? '';
$user_role_lower = strtolower($user_role); // Convert to lowercase for comparison
$can_process_payroll = isAdmin() || canEdit('payroll') || in_array($user_role_lower, ['admin', 'accountant', 'manager', 'hr']);

// DEBUG: Log permission check result
error_log("Preview Payroll - Can process: " . ($can_process_payroll ? 'YES' : 'NO'));

if (!$can_process_payroll) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to preview payroll. Your role: ' . $user_role]);
    exit();
}

try {
    // DEBUG: Log POST data
    error_log("Preview Payroll - POST Data: " . print_r($_POST, true));

    // Get form data
    // Get form data - Robust fallback for both payroll_period and period names
    $payroll_period = trim($_POST['payroll_period'] ?? $_POST['period'] ?? '');
    $department_id = trim($_POST['department_id'] ?? '');
    $employment_status = trim($_POST['employment_status'] ?? '');
    $include_allowances = isset($_POST['include_allowances']);
    $include_deductions = isset($_POST['include_deductions']);
    $include_attendance = isset($_POST['include_attendance']);

    // DEBUG: Log extracted values
    error_log("Preview Payroll - payroll_period: '" . $payroll_period . "'");

    if (empty($payroll_period)) {
        echo json_encode(['success' => false, 'message' => 'Payroll period is required.']);
        exit();
    }

    // Build employee query
    $scopeFilter = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'e') : '';
    $query = "SELECT e.employee_id, e.first_name, e.last_name, e.basic_salary, e.department_id
              FROM employees e
              WHERE e.status = 'active' $scopeFilter";

    $params = [];

    if (!empty($department_id)) {
        $query .= " AND e.department_id = ?";
        $params[] = $department_id;
    }

    if (!empty($employment_status)) {
        $query .= " AND e.employment_status = ?";
        $params[] = $employment_status;
    }

    // DEBUG: Log Query
    error_log("Preview Payroll - Query: " . $query);
    error_log("Preview Payroll - Params: " . print_r($params, true));

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // DEBUG: Log Result Count
    error_log("Preview Payroll - Employees Found: " . count($employees));

    if (empty($employees)) {
        echo json_encode(['success' => false, 'message' => 'No employees found matching the criteria']);
        exit();
    }

    $preview_data = [];

    foreach ($employees as $employee) {
        $basic_salary = floatval($employee['basic_salary']);
        $allowances = 0;
        $deductions = 0;
        $tax_amount = 0;

        // Calculate allowances if enabled
        if ($include_allowances) {
            $allowance_stmt = $pdo->prepare("
                SELECT SUM(amount) as total
                FROM employee_allowances
                WHERE employee_id = ? AND status = 'active'
            ");
            $allowance_stmt->execute([$employee['employee_id']]);
            $allowance_result = $allowance_stmt->fetch(PDO::FETCH_ASSOC);
            $allowances = floatval($allowance_result['total'] ?? 0);
        }

        // Plan H2 — attendance-driven mode (feature-flagged; default 'off' = legacy).
        $att_mode = attendancePayrollMode($pdo);
        $att_overtime = 0.0; $att_deduction = 0.0;

        // Adjust for attendance if enabled
        if ($include_attendance) {
            if ($att_mode === 'on') {
                $att_summary = payrollAttendanceSummary($pdo, (int)$employee['employee_id'], $payroll_period);
                $work_days = (float)($pdo->query("SELECT setting_value FROM payroll_settings WHERE setting_key = 'working_days_per_month'")->fetchColumn() ?: 22);
                if ($work_days <= 0) $work_days = 22;
                $per_day = $basic_salary / $work_days;
                $unpaid_leave_days = unpaidLeaveDaysInPeriod($pdo, (int)$employee['employee_id'], $payroll_period);
                $att_deduction = round($per_day * ($att_summary['absent_days'] + 0.5 * $att_summary['half_days'] + $unpaid_leave_days), 2);
                $att_overtime  = round($att_summary['overtime_amount'], 2);
            } else {
                // Legacy behaviour — unchanged.
                $attendance_stmt = $pdo->prepare("
                    SELECT COUNT(*) as present_days
                    FROM attendance
                    WHERE employee_id = ?
                    AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
                    AND status IN ('present', 'late')
                ");
                $attendance_stmt->execute([$employee['employee_id'], $payroll_period]);
                $present_days = intval(($attendance_stmt->fetch(PDO::FETCH_ASSOC))['present_days'] ?? 0);

                $half_day_stmt = $pdo->prepare("
                    SELECT COUNT(*) as half_days
                    FROM attendance
                    WHERE employee_id = ?
                    AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
                    AND status = 'half_day'
                ");
                $half_day_stmt->execute([$employee['employee_id'], $payroll_period]);
                $half_days = intval(($half_day_stmt->fetch(PDO::FETCH_ASSOC))['half_days'] ?? 0);

                $effective_days = $present_days + ($half_days * 0.5);
                if ($effective_days < 22) {
                    $daily_rate = $basic_salary / 22;
                    $basic_salary = $daily_rate * $effective_days;
                }
            }
        }

        // Plan H1 — component-based salary structure (preview mirrors process_payroll).
        // When the employee has assigned components they define allowances & deductions;
        // otherwise the legacy lump path runs unchanged.
        $comp = resolveEmployeeSalaryComponents($pdo, (int)$employee['employee_id'], $basic_salary);
        $use_components = $comp['has_components'];
        if ($use_components) {
            $allowances = $comp['allowances'];
            $deductions = $comp['deductions'];
        }

        // Plan H2 — overtime adds to earnings, attendance shortfall to deductions (mode on).
        if ($att_mode === 'on' && $include_attendance) {
            if ($att_overtime > 0)  $allowances += $att_overtime;
            if ($att_deduction > 0) $deductions += $att_deduction;
        }

        // Gross = basic + allowances.
        $gross_salary = $basic_salary + $allowances;

        // Statutory: NSSF (pre-tax) + PAYE on (gross − NSSF), period-dated.
        // Same engine as process_payroll so the preview matches the saved result.
        $nssf_employee = 0.0;
        if ($include_deductions) {
            if (!$use_components) {
                $deduction_stmt = $pdo->prepare("
                    SELECT SUM(amount) as total
                    FROM employee_deductions
                    WHERE employee_id = ? AND status = 'active'
                ");
                $deduction_stmt->execute([$employee['employee_id']]);
                $deduction_result = $deduction_stmt->fetch(PDO::FETCH_ASSOC);
                $deductions = floatval($deduction_result['total'] ?? 0);
            }

            $stat = computeEmployeeStatutory($pdo, $gross_salary, $payroll_period . '-01');
            $nssf_employee = $stat['nssf_employee'];
            $tax_amount    = $stat['paye'];
        }

        // Net = gross − (other deductions + NSSF + PAYE).
        $total_deductions = $deductions + $nssf_employee + $tax_amount;
        $net_salary = $gross_salary - $total_deductions;

        $preview_data[] = [
            'employee_id' => $employee['employee_id'],
            'employee_name' => $employee['first_name'] . ' ' . $employee['last_name'],
            'basic_salary' => number_format($basic_salary, 2, '.', ''),
            'allowances' => number_format($allowances, 2, '.', ''),
            'nssf' => number_format($nssf_employee, 2, '.', ''),
            'paye' => number_format($tax_amount, 2, '.', ''),
            'deductions' => number_format($total_deductions, 2, '.', ''),
            'net_salary' => number_format($net_salary, 2, '.', '')
        ];
    }

    // Log preview action
    logAudit($pdo, $_SESSION['user_id'], 'preview_payroll', [
        'activity_type' => 'view',
        'entity_type' => 'payroll',
        'description' => "User generated a payroll preview for period $payroll_period. Count: " . count($preview_data) . " employees."
    ]);

    echo json_encode([
        'success' => true,
        'data' => $preview_data,
        'count' => count($preview_data)
    ]);

} catch (Exception $e) {
    error_log("Preview Payroll Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while generating preview: ' . $e->getMessage()
    ]);
}
?>
