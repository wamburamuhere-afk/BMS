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

    // Preferences are stored as a JSON string in the users table
    $preferences = json_encode($_POST);

    $stmt = $pdo->prepare("UPDATE users SET notification_preferences = ? WHERE user_id = ?");
    $stmt->execute([$preferences, $userId]);

    logActivity($pdo, $userId, "Updated Notification Preferences", "User ID: $userId");

    echo json_encode(['success' => true, 'message' => "Preferences saved successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
