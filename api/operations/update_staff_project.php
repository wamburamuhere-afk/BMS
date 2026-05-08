<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

$employee_id = $_POST['employee_id'] ?? null;
$project_id = $_POST['project_id'] ?? null; // Can be null (or empty string) to unassign
$user_id = $_SESSION['user_id'] ?? 0;

if (!$employee_id) {
    echo json_encode(["success" => false, "message" => "Missing employee ID"]);
    exit;
}

// Convert empty string/null to actual NULL for DB
$db_project_id = ($project_id === '' || $project_id === 'null' || $project_id === null) ? null : $project_id;

try {
    $stmt = $pdo->prepare("UPDATE employees SET project_id = ? WHERE employee_id = ?");
    $stmt->execute([$db_project_id, $employee_id]);
    
    // Log action
    if ($db_project_id) {
        logAudit($pdo, $user_id, 'Staff Assigned to Project', [
            'entity_type' => 'projects',
            'entity_id' => $db_project_id,
            'description' => "Employee ID $employee_id assigned to Project ID $db_project_id",
            'activity_type' => 'Update'
        ]);
    } else {
        logAudit($pdo, $user_id, 'Staff Unassigned from Project', [
            'entity_type' => 'employees',
            'entity_id' => $employee_id,
            'description' => "Employee ID $employee_id removed from project",
            'activity_type' => 'Update'
        ]);
    }

    echo json_encode(["success" => true, "message" => "Assignment updated successfully"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
