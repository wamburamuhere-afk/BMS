<?php
require_once '../roots.php';
require_once '../includes/config.php';
require_once '../helpers.php';

header('Content-Type: application/json');

// Check if user is logged in (session already started by roots.php)
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
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($user_id <= 0) {
        throw new Exception('Invalid user ID');
    }

    // Determine new status
    $new_status = ($action === 'activate') ? 1 : 0;

    // Prevent deactivating own account
    if ($user_id == $_SESSION['user_id']) {
        throw new Exception('You cannot deactivate your own account');
    }

    // Get old status for audit
    $oldStatusStmt = $pdo->prepare("SELECT is_active FROM users WHERE user_id = ?");
    $oldStatusStmt->execute([$user_id]);
    $old_status = $oldStatusStmt->fetchColumn();

    // Update user status
    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
    $result = $stmt->execute([$new_status, $user_id]);

    // Deactivating must take effect immediately, not just block the NEXT
    // login (actions/login.php's is_active check handles that) — end
    // whatever session they're using RIGHT NOW too, the same way Login
    // History's own "End Session" admin action does.
    if ($result && $new_status === 0) {
        require_once '../core/session_tracker.php';
        $openRows = $pdo->prepare("SELECT id FROM user_sessions WHERE user_id = ? AND logout_at IS NULL");
        $openRows->execute([$user_id]);
        foreach ($openRows->fetchAll(PDO::FETCH_COLUMN) as $openId) {
            revokeUserSession($pdo, (int) $openId, (int) $_SESSION['user_id'], 'blocked');
        }
    }

    if ($result) {
        $action_label = ($action === 'activate') ? 'activated' : 'deactivated';

        // Log action
        logAudit($pdo, $_SESSION['user_id'], 'toggle_user_status', [
            'entity_type' => 'user',
            'entity_id' => $user_id,
            'description' => "User ID: $user_id was $action_label",
            'old_values' => ['is_active' => $old_status],
            'new_values' => ['is_active' => $new_status]
        ]);

        $message = ($action === 'activate') ? 'User activated successfully' : 'User deactivated successfully';
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        throw new Exception('Failed to update user status');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
