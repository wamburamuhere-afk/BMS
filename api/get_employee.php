<?php
// File: api/get_employee.php
ini_set('display_errors', 0);
require_once __DIR__ . '/../roots.php';

// Clean output buffer
if (ob_get_length()) ob_clean();

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Validate ID
if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
    exit();
}

$id = intval($_GET['id']);

try {
    // Fetch employee data
    // We select * to define all fields for the edit form
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->execute([$id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($employee) {
        // Phase D — project-scope gate (NULL project_id treated as global / visible)
        $emp_project_id = $employee['project_id'] ?? null;
        if (!empty($emp_project_id) && function_exists('userCan') && !userCan('project', (int)$emp_project_id)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied: this employee belongs to a project not in your scope.']);
            exit;
        }

        // Log Audit
        require_once HELPERS_FILE;
        logAudit($pdo, $_SESSION['user_id'], 'view', [
            'activity_type' => 'view',
            'entity_type' => 'employee',
            'entity_id' => $id,
            'description' => "Viewed/Fetched full details for employee: {$employee['first_name']} {$employee['last_name']} (ID: $id) for editing"
        ]);
        
        echo json_encode(['success' => true, 'data' => $employee]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
    }
} catch (Exception $e) {
    error_log("Error fetching employee: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>
