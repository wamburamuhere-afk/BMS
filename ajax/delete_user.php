<?php
require_once '../roots.php';
require_once '../includes/config.php';
require_once '../helpers.php';

session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check admin permissions
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

    if ($user_id <= 0) {
        throw new Exception('Invalid user ID');
    }

    // Prevent deleting own account
    if ($user_id == $_SESSION['user_id']) {
        throw new Exception('You cannot delete your own account');
    }

    // Get user details for audit before deletion
    $userStmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
    $userStmt->execute([$user_id]);
    $username = $userStmt->fetchColumn();

    // Delete user
    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
    $result = $stmt->execute([$user_id]);

    if ($result) {
        // Log action
        logAudit($pdo, $_SESSION['user_id'], 'delete_user', [
            'entity_type' => 'user',
            'entity_id' => $user_id,
            'description' => "Deleted user '{$username}' (ID: $user_id)",
            'old_values' => ['username' => $username],
            'new_values' => null
        ]);

        echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
    } else {
        throw new Exception('Failed to delete user');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
