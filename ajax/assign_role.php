<?php
require_once '../roots.php';
require_once '../includes/config.php';
require_once '../helpers.php';

// Header formatting for JSON
header('Content-Type: application/json');

// Check if user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if user has permission to assign roles (Admin check)
// This logic might need to be more sophisticated depending on your permission system
require_once '../core/permissions.php';
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $role_id = isset($_POST['role_id']) ? intval($_POST['role_id']) : 0;

    if ($user_id <= 0) {
        throw new Exception('Invalid user ID');
    }

    if ($role_id <= 0) {
        throw new Exception('Invalid role ID');
    }

    // Verify role exists
    $roleStmt = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
    $roleStmt->execute([$role_id]);
    $role = $roleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        throw new Exception('Role not found');
    }

    // Get old role for audit
    $oldRoleStmt = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
    $oldRoleStmt->execute([$user_id]);
    $oldRoleName = $oldRoleStmt->fetchColumn() ?: 'None';

    // Update user's role
    $stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE user_id = ?");
    $result = $stmt->execute([$role_id, $user_id]);

    if ($result) {
        // Log action
        logAudit($pdo, $_SESSION['user_id'], 'assign_role', [
            'entity_type' => 'user',
            'entity_id' => $user_id,
            'description' => "Assigned role '{$role['role_name']}' (ID: $role_id) to user ID: $user_id",
            'old_values' => ['role' => $oldRoleName],
            'new_values' => ['role' => $role['role_name']]
        ]);

        echo json_encode(['success' => true, 'message' => 'Role assigned successfully']);
    } else {
        throw new Exception('Failed to update role');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
