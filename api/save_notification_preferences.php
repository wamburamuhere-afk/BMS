<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

try {
    $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) throw new Exception("Not authenticated");

    // Preferences are stored as a JSON string in the users table
    $preferences = json_encode($_POST);

    $stmt = $pdo->prepare("UPDATE users SET notification_preferences = ? WHERE user_id = ?");
    $stmt->execute([$preferences, $userId]);

    echo json_encode(['success' => true, 'message' => "Preferences saved successfully"]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
