<?php
// API: List appraisals (Tier 3, Phase 3.3) — for the Appraisals tab + stat cards.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('hr_performance')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$cycle_id    = intval($_GET['cycle_id'] ?? 0);
$status      = trim($_GET['status'] ?? '');
$employee_id = intval($_GET['employee_id'] ?? 0);

$where = ["a.status != 'deleted'"];
$params = [];
if ($cycle_id)    { $where[] = "a.cycle_id = ?"; $params[] = $cycle_id; }
if ($status !== '') { $where[] = "a.status = ?"; $params[] = $status; }
if ($employee_id) {
    $where[] = "a.employee_id = ?"; $params[] = $employee_id;
    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);
} else {
    $where[] = "1=1" . scopeFilterSqlNullable('project', 'e');
}

try {
    $sql = "
        SELECT a.appraisal_id, a.cycle_id, a.employee_id, a.appraisal_date, a.overall_rating,
               a.status, a.created_by, a.approved_by,
               e.first_name, e.last_name, des.designation_name, c.cycle_name
        FROM employee_appraisals a
        JOIN employees e ON e.employee_id = a.employee_id
        LEFT JOIN designations des ON des.designation_id = a.designation_id
        LEFT JOIN appraisal_cycles c ON c.cycle_id = a.cycle_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.appraisal_date DESC, a.appraisal_id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stat cards (same scope/cycle filter, this cycle if one is chosen)
    $stats = ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'avg' => null];
    $sum = 0; $cnt = 0;
    foreach ($rows as $r) {
        if (isset($stats[$r['status']])) $stats[$r['status']]++;
        if ($r['status'] === 'approved' && $r['overall_rating'] !== null) { $sum += (float)$r['overall_rating']; $cnt++; }
    }
    if ($cnt > 0) $stats['avg'] = round($sum / $cnt, 2);

    echo json_encode(['success' => true, 'data' => $rows, 'stats' => $stats]);

} catch (Exception $e) {
    error_log("get_appraisals error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
