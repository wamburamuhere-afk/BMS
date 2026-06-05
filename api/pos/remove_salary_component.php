<?php
// api/pos/remove_salary_component.php — retire an employee's salary-component assignment.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('payroll')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $id = (int)($_POST['employee_component_id'] ?? 0);
    if ($id <= 0) throw new Exception('Missing assignment.');

    $row = $pdo->prepare("SELECT employee_id FROM employee_salary_components WHERE employee_component_id = ?");
    $row->execute([$id]);
    $empId = (int)$row->fetchColumn();
    if (!$empId) throw new Exception('Assignment not found.');
    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($empId);

    $pdo->prepare("UPDATE employee_salary_components SET status = 'inactive', end_date = CURDATE(), updated_at = NOW() WHERE employee_component_id = ?")->execute([$id]);

    logActivity($pdo, $_SESSION['user_id'], "Removed salary component", "assignment #$id (employee #$empId)");
    echo json_encode(['success' => true, 'message' => 'Component removed.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
