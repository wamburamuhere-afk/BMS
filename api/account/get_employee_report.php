<?php
/**
 * api/account/get_employee_report.php
 *
 * AJAX data source for the Workforce Analysis report — headcount/payroll
 * summary, three chart datasets, and per-employee rows.
 *
 * Project-scoped per security.md §23 (employees.project_id): a non-admin only
 * sees employees on their assigned projects (+ untagged company-wide staff).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('employee_report')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$department_id = $_GET['department_id'] ?? '';
$status        = $_GET['status'] ?? '';
$project_id    = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    $params = [];
    $where  = ["1=1"];
    if ($department_id !== '') { $where[] = "e.department_id = ?"; $params[] = (int)$department_id; }
    if ($status !== '')        { $where[] = "e.employment_status = ?"; $params[] = $status; }
    $scope = '';
    if ($project_id !== null) { $where[] = "e.project_id = ?"; $params[] = $project_id; }
    else                      { $scope = scopeFilterSqlNullable('project', 'e'); }
    $where_sql = implode(' AND ', $where) . $scope;

    // Rows
    $stmt = $pdo->prepare("
        SELECT e.employee_id,
               CONCAT(COALESCE(e.first_name,''),' ',COALESCE(e.last_name,'')) AS full_name,
               COALESCE(d.department_name, 'Unassigned')   AS department,
               COALESCE(des.designation_name, '—')         AS position,
               e.employment_status                          AS status,
               e.hire_date,
               COALESCE(e.basic_salary, 0)                  AS basic_salary
          FROM employees e
          LEFT JOIN departments d   ON e.department_id  = d.department_id
          LEFT JOIN designations des ON e.designation_id = des.designation_id
         WHERE $where_sql
      ORDER BY d.department_name ASC, full_name ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_salary = array_sum(array_map(fn($r) => (float)$r['basic_salary'], $rows));
    $active = count(array_filter($rows, fn($r) => strtolower($r['status'] ?? '') === 'active'));

    // By department (count) + by status (count)
    $byDept = []; $byStatus = [];
    foreach ($rows as $r) {
        $d = $r['department'] ?: 'Unassigned';
        $byDept[$d] = ($byDept[$d] ?? 0) + 1;
        $s = $r['status'] ?: 'unknown';
        $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
    }
    arsort($byDept);

    // Salary by department
    $salByDept = [];
    foreach ($rows as $r) { $d = $r['department'] ?: 'Unassigned'; $salByDept[$d] = ($salByDept[$d] ?? 0) + (float)$r['basic_salary']; }
    arsort($salByDept);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_workforce' => count($rows),
            'active'          => $active,
            'total_salary'    => round($total_salary, 2),
            'departments'     => count($byDept),
        ],
        'charts' => [
            'by_department'    => array_map(fn($k,$v) => ['label'=>$k,'value'=>$v], array_keys($byDept), array_values($byDept)),
            'by_status'        => array_map(fn($k,$v) => ['label'=>ucfirst($k),'value'=>$v], array_keys($byStatus), array_values($byStatus)),
            'salary_by_dept'   => array_map(fn($k,$v) => ['label'=>$k,'value'=>round($v,2)], array_keys($salByDept), array_values($salByDept)),
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_employee_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
