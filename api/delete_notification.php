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

    $id = $_POST['notification_id'] ?? null;
    if (!$id) throw new Exception("Notification ID required");

    $stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);

    logActivity($pdo, $userId, "Delete notification", "deleted notification with id $id");

    echo json_encode(['success' => true, 'message' => "Deleted successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
