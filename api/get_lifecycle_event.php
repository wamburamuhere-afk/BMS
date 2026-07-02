<?php
// API: Get a single Employee Lifecycle Event (view modal — Tier 1)
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('employee_lifecycle')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$event_id = intval($_GET['event_id'] ?? $_GET['id'] ?? 0);
if (!$event_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid event ID']);
    exit;
}

// Project-scope gate — follows the event's employee to their project
if (function_exists('assertScopeForEmployeeRecord')) {
    assertScopeForEmployeeRecord('employee_lifecycle_events', 'event_id', $event_id);
}

try {
    $stmt = $pdo->prepare("
        SELECT ele.*,
               e.first_name, e.last_name, e.employee_code, e.photo,
               od.designation_name  AS old_designation_name,
               nd.designation_name  AS new_designation_name,
               odp.department_name  AS old_department_name,
               ndp.department_name  AS new_department_name,
               op.project_name      AS old_project_name,
               np.project_name      AS new_project_name,
               cu.username          AS created_by_name,
               au.username          AS approved_by_name
        FROM employee_lifecycle_events ele
        JOIN employees e        ON e.employee_id = ele.employee_id
        LEFT JOIN designations od ON od.designation_id = ele.old_designation_id
        LEFT JOIN designations nd ON nd.designation_id = ele.new_designation_id
        LEFT JOIN departments odp ON odp.department_id = ele.old_department_id
        LEFT JOIN departments ndp ON ndp.department_id = ele.new_department_id
        LEFT JOIN projects op     ON op.project_id = ele.old_project_id
        LEFT JOIN projects np     ON np.project_id = ele.new_project_id
        LEFT JOIN users cu        ON cu.user_id = ele.created_by
        LEFT JOIN users au        ON au.user_id = ele.approved_by
        WHERE ele.event_id = ? AND ele.status != 'deleted'
    ");
    $stmt->execute([$event_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Event not found']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $row]);

} catch (Exception $e) {
    error_log("get_lifecycle_event error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
