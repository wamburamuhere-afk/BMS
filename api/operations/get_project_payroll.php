<?php
// api/operations/get_project_payroll.php
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

$period = !empty($_GET['period']) ? trim($_GET['period']) : date('Y-m');
$status = !empty($_GET['status']) ? trim($_GET['status']) : '';

try {
    $parts  = explode('-', $period);
    $year   = intval($parts[0] ?? date('Y'));
    $month  = intval($parts[1] ?? date('m'));

    $where  = "WHERE e.project_id = ? AND p.year = ? AND p.month = ?";
    $params = [$project_id, $year, $month];

    if ($status) {
        $where   .= " AND (p.status = ? OR p.payment_status = ?)";
        $params[] = $status;
        $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT p.payroll_id, p.payroll_number, p.basic_salary, p.gross_salary,
               p.total_allowances, p.total_deductions, p.tax_amount, p.net_salary,
               p.status, p.payment_status, p.year, p.month, p.created_at,
               e.employee_id, e.employee_number, e.first_name, e.last_name,
               d.department_name
        FROM payroll p
        JOIN employees e ON p.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        $where
        ORDER BY e.first_name ASC
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Active staff count for this project
    $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE project_id = ? AND status != 'terminated'");
    $activeStmt->execute([$project_id]);
    $active_count = (int)$activeStmt->fetchColumn();

    $stats = ['active' => $active_count, 'paid' => 0, 'pending' => 0, 'total_payout' => 0];
    foreach ($records as $r) {
        $s = strtolower($r['payment_status'] ?: $r['status']);
        if ($s === 'paid') $stats['paid']++;
        elseif (in_array($s, ['pending', 'approved', 'processing'])) $stats['pending']++;
        $stats['total_payout'] += floatval($r['net_salary']);
    }

    echo json_encode(['success' => true, 'data' => $records, 'stats' => $stats]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
