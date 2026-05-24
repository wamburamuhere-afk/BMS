<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) throw new Exception("Not authenticated");

    // User-personal endpoint: row-level scoped to user_id below; canView gate
    // is defense in depth (admin auto-bypasses).
    if (!canView('notification_center')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have access to the notification center');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        $message = "All notifications marked as read";
    } elseif ($action === 'clear_all_read') {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
        $stmt->execute([$userId]);
        $message = "All read notifications cleared";
    } else {
        throw new Exception("Invalid bulk action");
    }

    logActivity($pdo, $userId, "Notification Bulk Action", "Action: $action, Affected: " . $stmt->rowCount());

    echo json_encode(['success' => true, 'message' => $message]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
