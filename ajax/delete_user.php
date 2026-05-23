<?php
// File: ajax/delete_user.php
// Deletes a user account. JSON endpoint.
// Output is buffered so a stray notice/warning/whitespace can never corrupt
// the JSON response (the cause of "Error communicating with server: OK").
ob_start();

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../helpers.php';

/**
 * Emit a clean JSON response — discards any buffered stray output first.
 */
function delete_user_respond(array $payload, int $code = 200) {
    if (ob_get_level() > 0) { ob_clean(); }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

// Must be logged in.
if (!isAuthenticated()) {
    delete_user_respond(['success' => false, 'message' => 'Unauthorized'], 401);
}

// Must be an administrator.
if (!isAdmin()) {
    delete_user_respond(['success' => false, 'message' => 'Permission denied'], 403);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if ($user_id <= 0) {
        throw new Exception('Invalid user ID');
    }

    // Prevent deleting own account.
    if ($user_id == ($_SESSION['user_id'] ?? 0)) {
        throw new Exception('You cannot delete your own account');
    }

    global $pdo;

    // Get the username for the audit log before deletion.
    $userStmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
    $userStmt->execute([$user_id]);
    $username = $userStmt->fetchColumn();
    if ($username === false) {
        throw new Exception('User not found');
    }

    // Delete the user.
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$user_id]);

    // Audit trail.
    logAudit($pdo, $_SESSION['user_id'], 'delete_user', [
        'entity_type' => 'user',
        'entity_id'   => $user_id,
        'description' => "Deleted user '{$username}' (ID: $user_id)",
        'old_values'  => ['username' => $username],
        'new_values'  => null,
    ]);

    delete_user_respond(['success' => true, 'message' => 'User deleted successfully']);

} catch (Throwable $e) {
    // Throwable (not just Exception) so a fatal Error still returns JSON.
    delete_user_respond(['success' => false, 'message' => $e->getMessage()], 500);
}
