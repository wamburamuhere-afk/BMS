<?php
// API: Delete Employee
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!canDelete('employees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete employees']);
    exit();
}

try {
    $employee_id = $_POST['employee_id'] ?? null;
    if (!$employee_id) {
        throw new Exception("Employee ID is required");
    }

    // Phase D — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('employees', 'employee_id', $employee_id);
    }

    $pdo->beginTransaction();

    // Check if can assume deletion
    // Get old values for logging
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $old_values = $stmt->fetch(PDO::FETCH_ASSOC);

    // Instead of DELETE, setting status to 'terminated' or 'deleted' is safer for HR.
    // Use 'terminated' as per business logic, or actually DELETE if no related records?
    // User requested "Delete", let's assume Soft Delete or actual Delete logic.
    // The previous get_employees filtered out 'terminated'. So update status is safer.
    
    $stmt = $pdo->prepare("UPDATE employees SET status = 'terminated', employment_status = 'terminated', updated_by = ? WHERE employee_id = ?");
    $stmt->execute([$_SESSION['user_id'], $employee_id]);

    // Audit trail (rich) + Activity Log feed (visible on activity_log.php).
    $emp_name = trim(($old_values['first_name'] ?? '') . ' ' . ($old_values['last_name'] ?? '')) ?: ('employee #' . $employee_id);
    logAudit($pdo, $_SESSION['user_id'], 'delete', [
        'activity_type' => 'delete',
        'entity_type' => 'employee',
        'entity_id' => $employee_id,
        'description' => "Deleted (Terminated) employee: {$old_values['first_name']} {$old_values['last_name']}",
        'old_values' => $old_values
    ]);
    logActivity($pdo, $_SESSION['user_id'], 'Delete employee',
        "deleted (terminated) employee \"{$emp_name}\" with id {$employee_id}");

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Employee deleted successfully']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
