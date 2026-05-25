<?php
// API: Update Employee Status
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canEdit('employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to change employee status']);
    exit();
}

try {
    $employee_id = $_POST['employee_id'] ?? null;
    $status = $_POST['status'] ?? null;
    
    if (!$employee_id || !$status) {
        throw new Exception("Employee ID and Status are required");
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('employees', 'employee_id', $employee_id);
    }

    $pdo->beginTransaction();

    // Get old values
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $old_values = $stmt->fetch(PDO::FETCH_ASSOC);

    $old_status = $old_values['employment_status'];

    $stmt = $pdo->prepare("UPDATE employees SET employment_status = ?, updated_by = ? WHERE employee_id = ?");
    $stmt->execute([$status, $_SESSION['user_id'], $employee_id]);

    // Log Audit
    logAudit($pdo, $_SESSION['user_id'], 'update_status', [
        'activity_type' => 'status_change',
        'entity_type' => 'employee',
        'entity_id' => $employee_id,
        'description' => "Changed status of employee {$old_values['first_name']} {$old_values['last_name']} from $old_status to $status",
        'old_values' => ['employment_status' => $old_status],
        'new_values' => ['employment_status' => $status]
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Employee status updated successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
