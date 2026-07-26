<?php
// api/operations/get_project_attendance.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$project_id = intval($_GET['project_id'] ?? 0);
if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID required']);
    exit();
}

// Phase D — project-scope gate
if (function_exists('userCan') && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
    exit();
}

$date_from  = !empty($_GET['date_from'])  ? trim($_GET['date_from'])  : date('Y-m-01');
$date_to    = !empty($_GET['date_to'])    ? trim($_GET['date_to'])    : date('Y-m-d');
$status     = !empty($_GET['status'])     ? trim($_GET['status'])     : '';

try {
    // One row per employee currently active on this project — attendance counts are
    // aggregated across the selected date range (same shape as the standalone
    // Attendance page's week/month view), not one row per daily record. That per-day
    // shape used to repeat every employee once per attendance record in range.
    $stmt = $pdo->prepare("
        SELECT
            e.employee_id, e.employee_number, e.first_name, e.last_name,
            d.department_name, des.designation_name,
            COALESCE(SUM(CASE WHEN a.total_hours > 0 AND a.total_hours <= 24 THEN a.total_hours ELSE 0 END), 0) AS total_hours,
            SUM(CASE WHEN a.status = 'present'  THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN a.status = 'late'     THEN 1 ELSE 0 END) AS late_count,
            SUM(CASE WHEN a.status = 'half_day' THEN 1 ELSE 0 END) AS half_day_count,
            SUM(CASE WHEN a.status = 'absent'   THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN a.status = 'leave'    THEN 1 ELSE 0 END) AS leave_count
        FROM employees e
        LEFT JOIN departments d   ON e.department_id  = d.department_id
        LEFT JOIN designations des ON e.designation_id = des.designation_id
        LEFT JOIN attendance a ON a.employee_id = e.employee_id
               AND a.attendance_date BETWEEN ? AND ?
        WHERE e.project_id = ? AND e.status = 'active'
        GROUP BY e.employee_id
        ORDER BY e.first_name ASC, e.last_name ASC
    ");
    $stmt->execute([$date_from, $date_to, $project_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Optional status filter: keep only employees who have at least one day of
    // that status within the range (aggregate-equivalent of the old per-day filter).
    if ($status) {
        $countKey = $status . '_count';
        $records = array_values(array_filter($records, function ($r) use ($countKey) {
            return isset($r[$countKey]) && (int)$r[$countKey] > 0;
        }));
    }

    $stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'half_day' => 0, 'leave' => 0, 'total_hours' => 0];
    foreach ($records as $r) {
        $stats['present']   += (int)$r['present_count'];
        $stats['late']      += (int)$r['late_count'];
        $stats['half_day']  += (int)$r['half_day_count'];
        $stats['absent']    += (int)$r['absent_count'];
        $stats['leave']     += (int)$r['leave_count'];
        $stats['total_hours'] += (float)$r['total_hours'];
    }

    echo json_encode([
        'success' => true,
        'data'    => $records,
        'stats'   => $stats
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
