<?php
// api/operations/preview_project_payroll.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $project_id         = intval($_POST['project_id']       ?? 0);
    $payroll_period     = trim($_POST['payroll_period']      ?? '');
    $department_id      = intval($_POST['department_id']     ?? 0);
    $employment_status  = trim($_POST['employment_status']   ?? '');
    $include_allowances = isset($_POST['include_allowances']);
    $include_deductions = isset($_POST['include_deductions']);
    $consider_attendance = isset($_POST['consider_attendance']);

    if (!$project_id || !$payroll_period) {
        throw new Exception('Project ID and payroll period are required');
    }

    // Phase D — project-scope gate
    if (function_exists('userCan') && !userCan('project', $project_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
        exit();
    }

    $parts = explode('-', $payroll_period);
    $year  = intval($parts[0]);
    $month = intval($parts[1]);

    $where  = "e.project_id = ? AND e.status != 'terminated'";
    $params = [$project_id];

    if ($department_id) {
        $where   .= " AND e.department_id = ?";
        $params[] = $department_id;
    }
    if ($employment_status) {
        $where   .= " AND e.employment_status = ?";
        $params[] = $employment_status;
    }

    $staff_stmt = $pdo->prepare("
        SELECT e.employee_id, e.first_name, e.last_name, e.basic_salary, e.employment_status
        FROM employees e
        WHERE $where
        ORDER BY e.first_name
    ");
    $staff_stmt->execute($params);
    $staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($staff_list)) {
        echo json_encode(['success' => true, 'data' => [], 'message' => 'No staff found for the selected filters.']);
        exit();
    }

    $data = [];
    foreach ($staff_list as $emp) {
        $basic      = floatval($emp['basic_salary'] ?? 0);
        $allowances = 0;
        $deductions = 0;

        if ($include_allowances) {
            $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_allowances WHERE employee_id=? AND status='active'");
            $s->execute([$emp['employee_id']]);
            $allowances = floatval($s->fetchColumn());
        }
        if ($include_deductions) {
            $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM employee_deductions WHERE employee_id=? AND status='active'");
            $s->execute([$emp['employee_id']]);
            $deductions = floatval($s->fetchColumn());
        }
        if ($consider_attendance) {
            $s = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND YEAR(attendance_date)=? AND MONTH(attendance_date)=? AND status='absent'");
            $s->execute([$emp['employee_id'], $year, $month]);
            $absent = intval($s->fetchColumn());
            $deductions += $absent * ($basic / 26);
        }

        $gross = $basic + $allowances;
        $net   = $gross - $deductions;

        $data[] = [
            'employee_name' => $emp['first_name'] . ' ' . $emp['last_name'],
            'basic_salary'  => $basic,
            'allowances'    => $allowances,
            'deductions'    => $deductions,
            'net_salary'    => $net,
        ];
    }

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
