<?php
// api/get_leave_entitlement.php — Plan H3. Drift-proof leave balance for one employee
// + leave type (entitled + carried_over − used = available). Read-only.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/leave_balance.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit(); }

$employee_id = (int)($_GET['employee_id'] ?? 0);
$leave_type  = trim($_GET['leave_type'] ?? '');   // the leaves enum value (annual/sick/…)
$year        = (int)($_GET['year'] ?? date('Y'));

if (!$employee_id || $leave_type === '') { echo json_encode(['success' => false, 'message' => 'Missing parameters']); exit(); }

if (function_exists('assertScopeForEmployee')) {
    try { assertScopeForEmployee($employee_id); } catch (Throwable $e) { echo json_encode(['success' => false, 'message' => 'Access denied']); exit(); }
}

try {
    $b = leaveBalanceFor($pdo, $employee_id, $leave_type, $year);
    echo json_encode(['success' => true, 'balance' => $b]);
} catch (Throwable $e) {
    error_log('get_leave_entitlement error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load the balance.']);
}
