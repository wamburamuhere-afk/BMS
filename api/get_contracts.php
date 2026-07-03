<?php
// API: List employee contracts (Tier 2, Phase 2.3) — for employee_contracts.php
// and the Contracts card on employee_details.php.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('employee_contracts')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$status       = trim($_GET['status'] ?? '');
$type         = trim($_GET['contract_type'] ?? '');
$employee_id  = intval($_GET['employee_id'] ?? 0);
$expiring_in  = ($_GET['expiring_in'] ?? '') !== '' ? intval($_GET['expiring_in']) : null;

$where  = ["ec.status != 'deleted'"];
$params = [];

if ($status !== '') { $where[] = "ec.status = ?"; $params[] = $status; }
if ($type !== '')   { $where[] = "ec.contract_type = ?"; $params[] = $type; }
if ($employee_id)   { $where[] = "ec.employee_id = ?"; $params[] = $employee_id; }
if ($expiring_in !== null) {
    $where[] = "ec.end_date IS NOT NULL AND ec.end_date >= CURDATE() AND DATEDIFF(ec.end_date, CURDATE()) <= ?";
    $params[] = $expiring_in;
}

// Project-scope: when a specific employee is requested, assertScopeForEmployee
// already gates it below; otherwise the list is scoped via the joined employees alias.
if ($employee_id && function_exists('assertScopeForEmployee')) {
    assertScopeForEmployee($employee_id);
} else {
    $where[] = "1=1" . scopeFilterSqlNullable('project', 'e');
}

try {
    $sql = "
        SELECT ec.*, e.first_name, e.last_name, e.employee_number,
               DATEDIFF(ec.end_date, CURDATE()) AS days_to_expiry
        FROM employee_contracts ec
        JOIN employees e ON e.employee_id = ec.employee_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ec.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (Exception $e) {
    error_log("get_contracts error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
