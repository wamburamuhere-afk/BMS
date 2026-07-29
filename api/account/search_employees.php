<?php
/**
 * api/account/search_employees.php
 *
 * Select2 AJAX source for the Employee Statement employee picker.
 * Returns active employees matching the search term.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['results' => []]);
    exit;
}
// financial_reports = original Employee Statement use; employee_lifecycle =
// HR Actions picker (Tier 1) — either grants access, neither is narrowed.
if (!canView('financial_reports') && !canView('employee_lifecycle')) {
    http_response_code(403);
    echo json_encode(['results' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');

// Opt-in only — every existing caller (Employee Statement, HR Actions, Contracts,
// Performance, Training, Salary) keeps today's active-only behaviour untouched.
// The one legitimate exception is spawning an OFFBOARDING checklist, which by
// definition often needs to reach someone who has already been deactivated.
$includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';

try {
    global $pdo;
    $like = '%' . $q . '%';

    $scope = scopeFilterSqlNullable('project', 'employees');
    $statusClause = $includeInactive ? "status != 'deleted'" : "status = 'active'";
    $sql = "
        SELECT employee_id AS id,
               CONCAT(first_name, ' ', last_name) AS name,
               employee_number
          FROM employees
         WHERE $statusClause" .
         ($q !== '' ? " AND (first_name LIKE ? OR last_name LIKE ? OR employee_number LIKE ?)" : "") . "
               $scope
         ORDER BY first_name ASC, last_name ASC
         LIMIT 20
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($q !== '' ? [$like, $like, $like] : []);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = [
            'id'   => (int)$r['id'],
            'text' => $r['name'] . ' (' . $r['employee_number'] . ')',
        ];
    }
    echo json_encode(['results' => $results]);

} catch (Throwable $e) {
    error_log('search_employees error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
