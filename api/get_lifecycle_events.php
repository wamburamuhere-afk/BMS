<?php
// API: List Employee Lifecycle Events (HR Actions — Tier 1)
// Filters: event_type, status, employee_id, date_from, date_to.
// Project scope: non-admins only see events of employees in their scope
// (scopeFilterSqlNullable on the joined employees alias).
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

try {
    $event_type  = trim($_GET['event_type'] ?? '');
    $status      = trim($_GET['status'] ?? '');
    $employee_id = intval($_GET['employee_id'] ?? 0);
    $date_from   = trim($_GET['date_from'] ?? '');
    $date_to     = trim($_GET['date_to'] ?? '');

    $where  = ["ele.status != 'deleted'"];
    $params = [];

    $valid_types = ['promotion', 'demotion', 'transfer', 'award', 'warning', 'complaint', 'resignation', 'termination'];
    if ($event_type !== '' && in_array($event_type, $valid_types, true)) {
        $where[]  = "ele.event_type = ?";
        $params[] = $event_type;
    }
    if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
        $where[]  = "ele.status = ?";
        $params[] = $status;
    }
    if ($employee_id) {
        if (function_exists('assertScopeForEmployee')) {
            assertScopeForEmployee($employee_id);
        }
        $where[]  = "ele.employee_id = ?";
        $params[] = $employee_id;
    }
    if ($date_from !== '' && strtotime($date_from)) {
        $where[]  = "ele.event_date >= ?";
        $params[] = $date_from;
    }
    if ($date_to !== '' && strtotime($date_to)) {
        $where[]  = "ele.event_date <= ?";
        $params[] = $date_to;
    }

    // Project scope on the joined employees alias (§23)
    $scope_sql = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'e') : '';
    $where_sql = implode(' AND ', $where) . $scope_sql;

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
               au.username          AS approved_by_name,
               TRIM(CONCAT(la.first_name, ' ', la.last_name)) AS leadership_assistant_name
        FROM employee_lifecycle_events ele
        JOIN employees e        ON e.employee_id = ele.employee_id
        LEFT JOIN employees la   ON la.employee_id = ele.leadership_assistant_id
        LEFT JOIN designations od ON od.designation_id = ele.old_designation_id
        LEFT JOIN designations nd ON nd.designation_id = ele.new_designation_id
        LEFT JOIN departments odp ON odp.department_id = ele.old_department_id
        LEFT JOIN departments ndp ON ndp.department_id = ele.new_department_id
        LEFT JOIN projects op     ON op.project_id = ele.old_project_id
        LEFT JOIN projects np     ON np.project_id = ele.new_project_id
        LEFT JOIN users cu        ON cu.user_id = ele.created_by
        LEFT JOIN users au        ON au.user_id = ele.approved_by
        WHERE $where_sql
        ORDER BY ele.event_date DESC, ele.event_id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);

} catch (Exception $e) {
    error_log("get_lifecycle_events error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
