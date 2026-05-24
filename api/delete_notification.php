<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) throw new Exception("Not authenticated");

    $id = $_POST['notification_id'] ?? null;
    if (!$id) throw new Exception("Notification ID required");

    $stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);

    logActivity($pdo, $userId, "Deleted Notification", "Notification ID: $id");

    echo json_encode(['success' => true, 'message' => "Deleted successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
