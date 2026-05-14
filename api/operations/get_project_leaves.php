<?php
// api/operations/get_project_leaves.php
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

$date_from  = !empty($_GET['date_from'])  ? trim($_GET['date_from'])  : date('Y-01-01');
$date_to    = !empty($_GET['date_to'])    ? trim($_GET['date_to'])    : date('Y-12-31');
$status     = !empty($_GET['status'])     ? trim($_GET['status'])     : '';
$leave_type = !empty($_GET['leave_type']) ? trim($_GET['leave_type']) : '';

try {
    $where  = "WHERE e.project_id = ? AND (l.start_date <= ? AND l.end_date >= ?)";
    $params = [$project_id, $date_to, $date_from];

    if ($status) {
        $where   .= " AND l.status = ?";
        $params[] = $status;
    }
    if ($leave_type) {
        $where   .= " AND l.leave_type = ?";
        $params[] = $leave_type;
    }

    $stmt = $pdo->prepare("
        SELECT l.leave_id, l.leave_type, l.start_date, l.end_date, l.total_days,
               l.reason, l.status, l.notes, l.created_at,
               e.employee_id, e.employee_number, e.first_name, e.last_name,
               d.department_name, u.username AS applied_by_name
        FROM leaves l
        JOIN employees e ON l.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN users u ON l.applied_by = u.user_id
        $where
        ORDER BY l.created_at DESC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0, 'total_days' => 0];
    foreach ($records as $r) {
        $stats['total']++;
        $s = $r['status'];
        if (isset($stats[$s])) $stats[$s]++;
        $stats['total_days'] += floatval($r['total_days']);
    }

    echo json_encode(['success' => true, 'data' => $records, 'stats' => $stats]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
