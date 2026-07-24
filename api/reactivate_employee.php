<?php
// API: Reactivate Employee (employee_inactivation_plan.md, Phase 2)
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/employee_status.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Same authorization boundary as inactivating.
if (!canDelete('employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to reactivate employees']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

csrf_check();

try {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    if (!$employee_id) {
        throw new Exception("Employee ID is required");
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('employees', 'employee_id', $employee_id);
    }

    $stmt = $pdo->prepare("SELECT first_name, last_name, status FROM employees WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        throw new Exception("Employee not found.");
    }
    if ($emp['status'] === 'active') {
        throw new Exception("This employee is already active.");
    }

    $pdo->beginTransaction();
    $result = reactivateEmployee($pdo, $employee_id, (int)$_SESSION['user_id']);

    $emp_name = trim($emp['first_name'] . ' ' . $emp['last_name']) ?: ('employee #' . $employee_id);
    logAudit($pdo, $_SESSION['user_id'], 'update_status', [
        'activity_type' => 'status_change',
        'entity_type'   => 'employee',
        'entity_id'     => $employee_id,
        'description'   => "Reactivated employee: {$emp_name}",
        'old_values'    => $result['old'],
        'new_values'    => $result['new'],
    ]);
    logActivity($pdo, $_SESSION['user_id'], 'Reactivate employee',
        "reactivated employee \"{$emp_name}\"");

    $pdo->commit();

    $message = 'Employee reactivated successfully.';
    if (!$result['has_live_contract']) {
        $message .= ' Note: this employee has no active or draft contract on file — '
                   . 'create one before running attendance, leave, or payroll for them.';
    }
    echo json_encode([
        'success'            => true,
        'message'            => $message,
        'has_live_contract'  => $result['has_live_contract'],
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
