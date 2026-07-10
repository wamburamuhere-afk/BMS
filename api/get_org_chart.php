<?php
// API: Get Org Chart data (Tier 2, Phase 2.4)
// Returns a flat list of active employees (id, name, photo, designation,
// department, reporting_to_id) — the page builds the collapsible tree
// client-side (employees with no manager, or whose manager isn't in the
// returned set, render as roots). Scope-filtered: non-admins see only their
// assigned project(s) + company-wide/untagged employees.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('org_chart')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    $scope = scopeFilterSqlNullable('project', 'e');
    $stmt = $pdo->query("
        SELECT e.employee_id, e.first_name, e.last_name, e.photo, e.reporting_to_id,
               des.designation_name, d.department_name
        FROM employees e
        LEFT JOIN designations des ON des.designation_id = e.designation_id
        LEFT JOIN departments d ON d.department_id = e.department_id
        WHERE e.status = 'active' $scope
        ORDER BY e.first_name, e.last_name
    ");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    error_log("get_org_chart error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
