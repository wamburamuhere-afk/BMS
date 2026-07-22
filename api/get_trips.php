<?php
// API: List business trips (+ stats) or a single trip (Tier 4, Phase 4.3).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('employee_trips')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

try {
    $trip_id = intval($_GET['trip_id'] ?? 0);
    if ($trip_id) {
        if (function_exists('assertScopeForEmployeeRecord')) assertScopeForEmployeeRecord('employee_trips', 'trip_id', $trip_id);
        $stmt = $pdo->prepare("
            SELECT t.*, e.first_name, e.last_name, e.employee_number, au.username AS approved_by_name,
                   ea.account_code AS expense_account_code, ea.account_name AS expense_account_name,
                   pa.account_code AS paid_from_account_code, pa.account_name AS paid_from_account_name
            FROM employee_trips t
            JOIN employees e ON e.employee_id = t.employee_id
            LEFT JOIN users au ON au.user_id = t.approved_by
            LEFT JOIN accounts ea ON ea.account_id = t.expense_account_id
            LEFT JOIN accounts pa ON pa.account_id = t.paid_from_account_id
            WHERE t.trip_id = ? AND t.status != 'deleted'
        ");
        $stmt->execute([$trip_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'message' => 'Trip not found']); exit; }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    $status = trim($_GET['status'] ?? '');
    $employee_id = intval($_GET['employee_id'] ?? 0);
    $where = ["t.status != 'deleted'"]; $params = [];
    if ($status !== '') { $where[] = "t.status = ?"; $params[] = $status; }
    if ($employee_id) {
        $where[] = "t.employee_id = ?"; $params[] = $employee_id;
        if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);
    } else {
        $where[] = "1=1" . scopeFilterSqlNullable('project', 'e');
    }

    $sql = "
        SELECT t.trip_id, t.employee_id, t.purpose, t.destination, t.start_date, t.end_date,
               t.estimated_cost, t.status, e.first_name, e.last_name
        FROM employee_trips t
        JOIN employees e ON e.employee_id = t.employee_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.start_date DESC, t.trip_id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $year = (int)date('Y');
    $stats = ['pending' => 0, 'approved' => 0, 'completed_year' => 0];
    foreach ($rows as $r) {
        if ($r['status'] === 'pending') $stats['pending']++;
        if ($r['status'] === 'approved') $stats['approved']++;
        if ($r['status'] === 'completed' && (int)substr($r['start_date'], 0, 4) === $year) $stats['completed_year']++;
    }
    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);

} catch (Exception $e) {
    error_log("get_trips error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
