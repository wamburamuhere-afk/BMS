<?php
// API: Inactivate Employee — soft, reversible (see employee_inactivation_plan.md)
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/employee_status.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Same authorization boundary the old Delete action used.
if (!canDelete('employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to inactivate employees']);
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

    $outcome = $_POST['outcome'] ?? 'terminated';
    if (!in_array($outcome, ['terminated', 'resigned', 'failed_probation'], true)) {
        throw new Exception("Invalid reason selected.");
    }
    $reason = trim($_POST['reason'] ?? '');

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
    if ($emp['status'] !== 'active') {
        throw new Exception("This employee is already inactive.");
    }

    $pdo->beginTransaction();
    $result = inactivateEmployee($pdo, $employee_id, (int)$_SESSION['user_id'], $outcome);

    $emp_name = trim($emp['first_name'] . ' ' . $emp['last_name']) ?: ('employee #' . $employee_id);
    $reasonSuffix = $reason !== '' ? " — {$reason}" : '';
    $outcomeLabel = ['terminated' => 'Contract Terminated', 'resigned' => 'Resigned', 'failed_probation' => 'Failed Probation'][$outcome];

    logAudit($pdo, $_SESSION['user_id'], 'update_status', [
        'activity_type' => 'status_change',
        'entity_type'   => 'employee',
        'entity_id'     => $employee_id,
        'description'   => "Inactivated employee: {$emp_name} ({$outcomeLabel}){$reasonSuffix}",
        'old_values'    => $result['old'],
        'new_values'    => $result['new'],
    ]);
    logActivity($pdo, $_SESSION['user_id'], 'Inactivate employee',
        "inactivated employee \"{$emp_name}\" ({$outcomeLabel}){$reasonSuffix}");

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Employee inactivated successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
