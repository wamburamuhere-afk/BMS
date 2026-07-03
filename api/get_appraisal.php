<?php
// API: Get a single appraisal with its scorecard items (Tier 3, Phase 3.3).
require_once __DIR__ . '/../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('hr_performance')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$appraisal_id = intval($_GET['appraisal_id'] ?? $_GET['id'] ?? 0);
if (!$appraisal_id) { echo json_encode(['success' => false, 'message' => 'Appraisal ID is required']); exit; }

if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_appraisals', 'appraisal_id', $appraisal_id);
}

try {
    $stmt = $pdo->prepare("
        SELECT a.*, e.first_name, e.last_name, e.employee_number,
               des.designation_name, c.cycle_name, c.period_from, c.period_to,
               cu.username AS created_by_name, au.username AS approved_by_name
        FROM employee_appraisals a
        JOIN employees e ON e.employee_id = a.employee_id
        LEFT JOIN designations des ON des.designation_id = a.designation_id
        LEFT JOIN appraisal_cycles c ON c.cycle_id = a.cycle_id
        LEFT JOIN users cu ON cu.user_id = a.created_by
        LEFT JOIN users au ON au.user_id = a.approved_by
        WHERE a.appraisal_id = ? AND a.status != 'deleted'
    ");
    $stmt->execute([$appraisal_id]);
    $appraisal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$appraisal) { echo json_encode(['success' => false, 'message' => 'Appraisal not found']); exit; }

    $items = $pdo->prepare("
        SELECT it.item_id, it.indicator_id, it.expected_rating, it.actual_rating, it.comment,
               i.indicator_name, cat.category_name, cat.sort_order
        FROM employee_appraisal_items it
        LEFT JOIN performance_indicators i ON i.indicator_id = it.indicator_id
        LEFT JOIN performance_indicator_categories cat ON cat.category_id = i.category_id
        WHERE it.appraisal_id = ?
        ORDER BY cat.sort_order, cat.category_name, i.indicator_name
    ");
    $items->execute([$appraisal_id]);

    echo json_encode(['success' => true, 'data' => $appraisal, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    error_log("get_appraisal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
