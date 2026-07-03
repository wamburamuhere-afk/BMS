<?php
// API: Add an employee goal (Tier 3, Phase 3.4).
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canCreate('hr_performance')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to create goals']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

try {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $goal_type_id = intval($_POST['goal_type_id'] ?? 0);
    $subject     = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date  = trim($_POST['start_date'] ?? '');
    $end_date    = trim($_POST['end_date'] ?? '');

    if (!$employee_id) throw new Exception('Employee is required');
    if (!$goal_type_id) throw new Exception('Goal type is required');
    if ($subject === '') throw new Exception('Subject is required');
    if (!strtotime($start_date) || !strtotime($end_date)) throw new Exception('Valid start and end dates are required');
    if (strtotime($end_date) < strtotime($start_date)) throw new Exception('End date must be on or after the start date');

    if (function_exists('assertScopeForEmployee')) assertScopeForEmployee($employee_id);

    $chkType = $pdo->prepare("SELECT goal_type_id FROM goal_types WHERE goal_type_id = ? AND status = 'active'");
    $chkType->execute([$goal_type_id]);
    if (!$chkType->fetch()) throw new Exception('Goal type does not exist or is inactive');

    $emp = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ? AND (status IS NULL OR status != 'deleted')");
    $emp->execute([$employee_id]);
    $er = $emp->fetch(PDO::FETCH_ASSOC);
    if (!$er) throw new Exception('Employee not found');
    $emp_name = trim($er['first_name'] . ' ' . $er['last_name']);

    $pdo->prepare("
        INSERT INTO employee_goals (employee_id, goal_type_id, subject, description, start_date, end_date, progress, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, 0, 'not_started', ?)
    ")->execute([$employee_id, $goal_type_id, $subject, ($description !== '' ? $description : null), $start_date, $end_date, $_SESSION['user_id']]);
    $goal_id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], 'Add goal', "set goal '$subject' for \"$emp_name\"");
    logAudit($pdo, $_SESSION['user_id'], 'create', [
        'activity_type' => 'create',
        'entity_type'   => 'employee_goal',
        'entity_id'     => $goal_id,
        'description'   => "Created goal '$subject' for $emp_name",
        'new_values'    => ['subject' => $subject, 'end_date' => $end_date, 'status' => 'not_started'],
    ]);

    echo json_encode(['success' => true, 'message' => 'Goal created', 'goal_id' => $goal_id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
