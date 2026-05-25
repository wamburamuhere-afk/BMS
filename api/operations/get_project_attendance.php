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
    $where  = "WHERE e.project_id = ? AND a.attendance_date BETWEEN ? AND ?";
    $params = [$project_id, $date_from, $date_to];

    if ($status) {
        $where   .= " AND a.status = ?";
        $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT a.attendance_id, a.attendance_date, a.check_in_time, a.check_out_time,
               a.total_hours, a.status, a.notes,
               e.employee_id, e.employee_number, e.first_name, e.last_name,
               d.department_name, des.designation_name
        FROM attendance a
        JOIN employees e ON a.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN designations des ON e.designation_id = des.designation_id
        $where
        ORDER BY a.attendance_date DESC, e.first_name ASC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'half_day' => 0, 'leave' => 0, 'total_hours' => 0];
    foreach ($records as $r) {
        $s = $r['status'];
        if (isset($stats[$s])) $stats[$s]++;
        $stats['total_hours'] += floatval($r['total_hours']);
    }

    echo json_encode([
        'success' => true,
        'data'    => $records,
        'stats'   => $stats
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
