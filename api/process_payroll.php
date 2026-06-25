<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/salary_structure.php';     // Plan H1 — component expansion
require_once __DIR__ . '/../core/attendance_payroll.php';   // Plan H2 — attendance-driven payroll
require_once __DIR__ . '/../core/leave_balance.php';        // Plan H3 — unpaid-leave deduction
require_once __DIR__ . '/../core/payroll_tax.php';          // Statutory engine — PAYE (on gross−NSSF), NSSF, SDL
require_once __DIR__ . '/../core/payment_source.php';       // Accrual + SDL posting helpers

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// DEBUG: Log session data
error_log("Process Payroll - Session Data: " . print_r($_SESSION, true));
error_log("Process Payroll - user_role: " . ($_SESSION['user_role'] ?? 'NOT SET'));

// Check permissions
$user_role = $_SESSION['user_role'] ?? '';
$user_role_lower = strtolower($user_role); // Convert to lowercase for comparison
// Check against hardcoded list OR the dynamic permission system
$can_process_payroll = isAdmin() || canEdit('payroll') || in_array($user_role_lower, ['admin', 'accountant', 'manager', 'hr', 'managing director']);

// DEBUG: Log permission check result
error_log("Process Payroll - Can process: " . ($can_process_payroll ? 'YES' : 'NO'));

if (!$can_process_payroll) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to process payroll. Your role: ' . $user_role]);
    exit();
}

try {
    // Get form data
    // Get form data - Robust fallback for both payroll_period and period names
    $payroll_period = trim($_POST['payroll_period'] ?? $_POST['period'] ?? '');
    $payroll_date = $_POST['payroll_date'] ?? date('Y-m-d');
    $department_id = $_POST['department_id'] ?? null;
    $employment_status = $_POST['employment_status'] ?? '';
    $include_allowances = isset($_POST['include_allowances']);
    $include_deductions = isset($_POST['include_deductions']);
    $include_attendance = isset($_POST['include_attendance']);
    $auto_approve = isset($_POST['auto_approve']);
    $notes = $_POST['notes'] ?? '';

    // DB Hardening: Ensure columns and ENUM values exist to prevent processing crashes
    try {
        $pdo->exec("ALTER TABLE payroll MODIFY COLUMN payment_status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial','voided') DEFAULT 'pending'");
        $pdo->exec("ALTER TABLE payroll MODIFY COLUMN status ENUM('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial','voided') DEFAULT 'pending'");

        // Ensure consistency columns exist
        $cols = [
            'payroll_number'       => "VARCHAR(50) AFTER payroll_id",
            'gross_salary'         => "DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount",
            'nssf_employee'        => "DECIMAL(15,2) DEFAULT 0.00 AFTER tax_amount",
            'nssf_employer'        => "DECIMAL(15,2) DEFAULT 0.00 AFTER nssf_employee",
            'month'                => "INT(2) AFTER net_salary",
            'year'                 => "INT(4) AFTER month",
            'payment_method'       => "VARCHAR(50) DEFAULT 'bank' AFTER status",
            'notes'                => "TEXT AFTER payment_method",
            'created_by'           => "INT AFTER notes",
            'updated_at'           => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
            'voided_by'            => "INT NULL DEFAULT NULL",
            'voided_at'            => "DATETIME NULL DEFAULT NULL",
            'void_reason'          => "TEXT NULL DEFAULT NULL",
        ];
        foreach ($cols as $col => $def) {
            try { $pdo->exec("ALTER TABLE payroll ADD COLUMN $col $def"); } catch(Exception $e) {}
        }
    } catch (Exception $e) { /* Ignore schema update errors if already set */ }

    // Pre-calculate month and year for efficiency
    $dt = strtotime($payroll_period . '-01');
    $month = (int)date('n', $dt);
    $year = (int)date('Y', $dt);

    // Validate required fields
    if (empty($payroll_period)) {
        throw new Exception('Payroll period is required');
    }

    if (empty($payroll_date)) {
        throw new Exception('Payroll date is required');
    }

    // Check if we are processing specific employees
    $specific_employee_ids = [];
    if (isset($_POST['employee_ids'])) {
        $specific_employee_ids = json_decode($_POST['employee_ids'], true);
        // Validate array
        if (!is_array($specific_employee_ids)) {
             $specific_employee_ids = []; // Fallback or throw error
        }
    }

    // Build query to get employees
    $scopeFilter = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'e') : '';
    $employee_query = "
        SELECT
            e.employee_id,
            e.employee_number,
            e.first_name,
            e.last_name,
            e.basic_salary,
            e.department_id,
            e.employment_status,
            e.payment_method,
            e.bank_account
        FROM employees e
        WHERE e.status = 'active' $scopeFilter
    ";

    $params = [];

    if (!empty($specific_employee_ids)) {
        // Filter by specific IDs
        $placeholders = str_repeat('?,', count($specific_employee_ids) - 1) . '?';
        $employee_query .= " AND e.employee_id IN ($placeholders)";
        $params = array_merge($params, $specific_employee_ids);
    } else {
        // Standard filters if not selecting specific employees
        if ($department_id) {
            $employee_query .= " AND e.department_id = ?";
            $params[] = $department_id;
        }

        if ($employment_status) {
            $employee_query .= " AND e.employment_status = ?";
            $params[] = $employment_status;
        }
    }

    $employee_query .= " ORDER BY e.first_name, e.last_name";

    $employee_stmt = $pdo->prepare($employee_query);
    $employee_stmt->execute($params);
    $employees = $employee_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($employees) == 0) {
        throw new Exception('No employees found matching the criteria');
    }

    // Check for existing payroll records FOR THESE EMPLOYEES in this period
    // We only want to prevent duplicates, not block partial processing
    $employee_ids_to_process = array_column($employees, 'employee_id');

    if (!empty($employee_ids_to_process)) {
        $placeholders = str_repeat('?,', count($employee_ids_to_process) - 1) . '?';
        $check_query = "
            SELECT employee_id
            FROM payroll
            WHERE payroll_period = ?
            AND employee_id IN ($placeholders)
            AND payment_status != 'voided'
        ";

        $check_params = array_merge([$payroll_period], $employee_ids_to_process);
        $check_stmt = $pdo->prepare($check_query);
        $check_stmt->execute($check_params);
        $existing_records = $check_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($existing_records)) {
            // Filter out employees who already have payroll
            $employees = array_filter($employees, function($emp) use ($existing_records) {
                return !in_array($emp['employee_id'], $existing_records);
            });

            if (empty($employees)) {
                 throw new Exception('All selected employees already have payroll records for this period.');
            }
        }
    }

    $pdo->beginTransaction();

    $total_processed = 0;
    $successful = 0;
    $failed = 0;
    $total_amount = 0;
    $errors = [];

    foreach ($employees as $employee) {
        try {
            $total_processed++;

            $basic_salary = floatval($employee['basic_salary'] ?? 0);
            $allowances = 0;
            $deductions = 0;
            $tax_amount = 0;

            // Calculate allowances if enabled
            if ($include_allowances) {
                // Get employee allowances
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
            $att_overtime = 0.0; $att_deduction = 0.0; $att_summary = null;

            // Consider attendance if enabled
            if ($include_attendance) {
                if ($att_mode === 'on') {
                    // Derive per-day deductions (absent + half-day) + overtime from attendance.
                    // Basic stays whole; the absent/half deduction is applied as a deduction
                    // line, and overtime is added to earnings, so the payslip is itemised.
                    $att_summary = payrollAttendanceSummary($pdo, (int)$employee['employee_id'], $payroll_period);
                    $work_days = (float)($pdo->query("SELECT setting_value FROM payroll_settings WHERE setting_key = 'working_days_per_month'")->fetchColumn() ?: 22);
                    if ($work_days <= 0) $work_days = 22;
                    $per_day = $basic_salary / $work_days;
                    $att_deduction = round($per_day * ($att_summary['absent_days'] + 0.5 * $att_summary['half_days']), 2);
                    // Plan H3 — approved UNPAID-leave days in the period also deduct per-day.
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
                    $attendance_result = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
                    $present_days = intval($attendance_result['present_days'] ?? 0);

                    if ($present_days < 22) {
                        $daily_rate = $basic_salary / 22;
                        $basic_salary = $daily_rate * $present_days;
                    }
                }
            }

            // Plan H1 — component-based salary structure. If this employee has assigned
            // salary components, they are the source of truth for allowances & deductions
            // (and produce an itemised payslip). Employees with NO components fall through
            // to the existing legacy employee_allowances / employee_deductions path,
            // byte-for-byte unchanged.
            $payroll_items_breakdown = [];
            $comp = resolveEmployeeSalaryComponents($pdo, (int)$employee['employee_id'], $basic_salary);
            $use_components = $comp['has_components'];
            if ($use_components) {
                $allowances = $comp['allowances'];
                $deductions = $comp['deductions'];
                $payroll_items_breakdown = $comp['items'];
            }

            // Plan H2 — when attendance mode is on, overtime adds to earnings and the
            // absent/half-day shortfall is a deduction; both become itemised payslip lines.
            if ($att_mode === 'on' && $include_attendance) {
                if ($att_overtime > 0) {
                    $allowances += $att_overtime;
                    $payroll_items_breakdown[] = ['item_type' => 'allowance', 'item_name' => 'Overtime', 'amount' => $att_overtime, 'tax_applicable' => 0];
                }
                if ($att_deduction > 0) {
                    $deductions += $att_deduction;
                    $payroll_items_breakdown[] = ['item_type' => 'deduction', 'item_name' => 'Attendance shortfall (absent / half-day)', 'amount' => $att_deduction, 'tax_applicable' => 0];
                }
            }

            // Calculate tax using progressive tax brackets
            // Gross is always basic + allowances (earnings).
            $gross_salary = $basic_salary + $allowances;

            // Non-statutory custom deductions from employee_deductions table.
            // Gated by $include_deductions (user checkbox) and skipped when the
            // component structure already supplies a deduction breakdown.
            $nssf_employee = 0.0;
            if ($include_deductions && !$use_components) {
                $deduction_stmt = $pdo->prepare("
                    SELECT SUM(amount) as total
                    FROM employee_deductions
                    WHERE employee_id = ? AND status = 'active'
                ");
                $deduction_stmt->execute([$employee['employee_id']]);
                $deduction_result = $deduction_stmt->fetch(PDO::FETCH_ASSOC);
                $deductions = floatval($deduction_result['total'] ?? 0);
            }

            // STATUTORY deductions — NSSF and PAYE are legal obligations; they are
            // always computed regardless of the include_deductions UI checkbox.
            // Resolved AS-OF the payroll period so a re-run of an old month uses the
            // bracket table that applied then. Single engine (core/payroll_tax.php).
            $stat = computeEmployeeStatutory($pdo, $gross_salary, $payroll_period . '-01');
            $nssf_employee = $stat['nssf_employee'];
            $tax_amount    = $stat['paye'];
            // Employer NSSF — separate company cost, not deducted from staff net pay.
            $nssf_employer = round($gross_salary * nssfEmployerRate($pdo) / 100, 2);
            if ($nssf_employee > 0) {
                $payroll_items_breakdown[] = ['item_type' => 'deduction',     'item_name' => 'NSSF (employee)',  'amount' => $nssf_employee, 'tax_applicable' => 0];
            }
            if ($nssf_employer > 0) {
                $payroll_items_breakdown[] = ['item_type' => 'employer_cost', 'item_name' => 'NSSF (employer)',  'amount' => $nssf_employer, 'tax_applicable' => 0];
            }
            if ($tax_amount > 0) {
                $payroll_items_breakdown[] = ['item_type' => 'deduction',     'item_name' => 'PAYE',             'amount' => $tax_amount,    'tax_applicable' => 0];
            }

            // Net = gross − (other deductions + NSSF + PAYE).
            $total_deductions = $deductions + $nssf_employee + $tax_amount;
            $net_salary = $gross_salary - $total_deductions;

            // Generate payroll number
            $payroll_number = 'PAY-' . date('Ym', strtotime($payroll_period . '-01')) . '-' . str_pad($employee['employee_id'], 4, '0', STR_PAD_LEFT);

            // Determine initial status
            $payment_status = $auto_approve ? 'approved' : 'pending';

            // Insert payroll record with full required column set
            $insert_stmt = $pdo->prepare("
                INSERT INTO payroll (
                    payroll_number, employee_id, payroll_period, payroll_date,
                    basic_salary, allowances, deductions, gross_salary,
                    tax_amount, nssf_employee, nssf_employer, net_salary, month, year,
                    payment_status, status, payment_method, notes,
                    created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $insert_stmt->execute([
                $payroll_number,
                $employee['employee_id'],
                $payroll_period,
                $payroll_date,
                $basic_salary,
                $allowances,
                $deductions,
                $gross_salary,
                $tax_amount,
                $nssf_employee,
                $nssf_employer,
                $net_salary,
                $month,
                $year,
                $payment_status,
                $payment_status,
                $employee['payment_method'] ?? 'bank',
                $notes,
                $_SESSION['user_id']
            ]);

            // Plan H1 — persist the itemised breakdown (idempotent; empty for legacy
            // employees, which keeps the lump display on the payslip unchanged).
            $payroll_id = (int)$pdo->lastInsertId();
            writePayrollItems($pdo, $payroll_id, $payroll_items_breakdown);

            // Accrual model: if this row is auto-approved here, book the liabilities now
            // (Dr Salaries Expense / Cr PAYE + NSSF + Salaries Payable) — recognised
            // regardless of whether the employee is paid yet.
            if ($payment_status === 'approved') {
                $accrualTxn = null;
                try { $accrualTxn = ensurePayrollAccrued($pdo, $payroll_id, (int)$_SESSION['user_id']); }
                catch (Throwable $e) { error_log('payroll accrual: ' . $e->getMessage()); }
                if (!$accrualTxn) {
                    $errors[] = "Employee {$employee['first_name']} {$employee['last_name']}: GL accrual not posted — verify Salaries Expense, PAYE Payable, NSSF Payable and Salaries Payable account mapping in System Settings.";
                }
            }

            $successful++;
            $total_amount += $net_salary;

        } catch (Exception $e) {
            $failed++;
            $errors[] = "Employee {$employee['first_name']} {$employee['last_name']}: " . $e->getMessage();
        }
    }

    // Refresh the statutory remittance schedule + the period's SDL accrual
    // (Dr SDL Expense / Cr SDL Payable). Wrapped so it can never break processing.
    try {
        $remSync = syncStatutoryRemittances($pdo, $payroll_period, (int)$_SESSION['user_id']);
        postSdlAccrual($pdo, $payroll_period, (float)($remSync['amounts']['sdl'] ?? 0), (int)$_SESSION['user_id']);
    } catch (Throwable $e) { error_log('statutory sync/accrual: ' . $e->getMessage()); }

    $pdo->commit();

    $summary = [
        'total_processed' => $total_processed,
        'successful' => $successful,
        'failed' => $failed,
        'total_amount' => number_format($total_amount, 2),
        'errors' => $errors
    ];

    $message = "Payroll processed successfully. $successful out of $total_processed employees processed.";
    if ($failed > 0) {
        $message .= " $failed failed.";
    }

    // Log successful processing
    logAudit($pdo, $_SESSION['user_id'], 'process_payroll', [
        'activity_type' => 'create',
        'entity_type' => 'payroll',
        'description' => "Processed payroll for $successful employees for period $payroll_period. Total Amount: " . number_format($total_amount, 2)
    ]);

    echo json_encode([
        'success' => $successful > 0,
        'message' => $successful > 0 ? $message : ($failed > 0 ? "Processing failed for all selected employees. " . implode(", ", $errors) : "No employees were processed."),
        'summary' => $summary
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Log failed processing
    logAudit($pdo, $_SESSION['user_id'], 'process_payroll_failed', [
        'activity_type' => 'create',
        'entity_type' => 'payroll',
        'description' => "Payroll processing failed: " . $e->getMessage()
    ]);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
