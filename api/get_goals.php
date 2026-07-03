<?php
// API: List employee goals (Tier 3, Phase 3.4) — Goals tab + stat cards.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('hr_performance')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$type_id     = intval($_GET['goal_type_id'] ?? 0);
$status      = trim($_GET['status'] ?? '');
$employee_id = intval($_GET['employee_id'] ?? 0);

$where = ["g.status != 'deleted'"];
$params = [];
if ($type_id)     { $where[] = "g.goal_type_id = ?"; $params[] = $type_id; }
if ($status !== '') { $where[] = "g.status = ?"; $params[] = $status; }
if ($employee_id) {
    $where[] = "g.employee_id = ?"; $params[] = $employee_id;
    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);
} else {
    $where[] = "1=1" . scopeFilterSqlNullable('project', 'e');
}

try {
    $sql = "
        SELECT g.*, e.first_name, e.last_name, t.type_name,
               DATEDIFF(g.end_date, CURDATE()) AS days_to_due
        FROM employee_goals g
        JOIN employees e ON e.employee_id = g.employee_id
        LEFT JOIN goal_types t ON t.goal_type_id = g.goal_type_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY g.end_date ASC, g.goal_id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stat cards — same scope/filter
    $stats = ['active' => 0, 'completed_year' => 0, 'overdue' => 0, 'avg_progress' => null];
    $sum = 0; $activeCnt = 0; $thisYear = (int)date('Y');
    foreach ($rows as $r) {
        $active = in_array($r['status'], ['not_started', 'in_progress'], true);
        if ($active) { $stats['active']++; $sum += (int)$r['progress']; $activeCnt++; }
        if ($r['status'] === 'completed' && (int)substr($r['updated_at'] ?? $r['end_date'], 0, 4) === $thisYear) $stats['completed_year']++;
        if ($active && (int)$r['days_to_due'] < 0) $stats['overdue']++;
    }
    if ($activeCnt > 0) $stats['avg_progress'] = (int)round($sum / $activeCnt);

    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);

} catch (Exception $e) {
    error_log("get_goals error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
