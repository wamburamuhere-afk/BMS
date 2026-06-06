<?php
// api/pos/assign_salary_component.php — assign a salary component to an employee
// (employee_salary_components). Additive; affects only future payroll runs.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();
if (!canEdit('payroll')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access Denied']); exit; }

try {
    $employee_id  = (int)($_POST['employee_id'] ?? 0);
    $component_id = (int)($_POST['component_id'] ?? 0);
    $amount       = round((float)($_POST['amount'] ?? 0), 2);
    $effective    = $_POST['effective_date'] ?? date('Y-m-d');

    if ($employee_id <= 0) throw new Exception('Missing employee.');
    if ($component_id <= 0) throw new Exception('Select a component.');
    if ($amount < 0) throw new Exception('Value cannot be negative.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective)) $effective = date('Y-m-d');

    // Employee must exist + be in the user's project scope.
    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);
    $emp = $pdo->prepare("SELECT employee_id FROM employees WHERE employee_id = ?");
    $emp->execute([$employee_id]);
    if (!$emp->fetchColumn()) throw new Exception('Employee not found.');

    // Component must be a real, active component; a % cannot exceed 100.
    $comp = $pdo->prepare("SELECT calculation_type FROM salary_components WHERE component_id = ? AND status = 'active'");
    $comp->execute([$component_id]);
    $calc = $comp->fetchColumn();
    if (!$calc) throw new Exception('That component is not available.');
    if ($calc === 'percentage' && $amount > 100) throw new Exception('A percentage cannot exceed 100%.');

    // Avoid duplicating an already-active assignment of the same component.
    $dup = $pdo->prepare("SELECT employee_component_id FROM employee_salary_components
                           WHERE employee_id = ? AND component_id = ? AND status = 'active'");
    $dup->execute([$employee_id, $component_id]);
    if ($dup->fetchColumn()) throw new Exception('That component is already assigned to this employee.');

    $pdo->prepare("INSERT INTO employee_salary_components (employee_id, component_id, amount, effective_date, status, created_by, created_at)
                   VALUES (?, ?, ?, ?, 'active', ?, NOW())")
        ->execute([$employee_id, $component_id, $amount, $effective, $_SESSION['user_id']]);

    logActivity($pdo, $_SESSION['user_id'], "Assigned salary component", "employee #$employee_id, component #$component_id, amount $amount");
    echo json_encode(['success' => true, 'message' => 'Component assigned.']);

} catch (Exception $e) {
    error_log('assign_salary_component error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
