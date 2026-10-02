<?php
// scope-audit: skip — row-level scoped to user_id; user can only mark their own notifications
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth(); mobileJsonBody();

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => t('Unauthorized')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => t('Method not allowed')]);
    exit;
}

global $pdo;
$user_id         = (int)$_SESSION['user_id'];
$notification_id = (int)($_POST['notification_id'] ?? 0);

if ($notification_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'notification_id is required']);
    exit;
}

try {
    // Row-level scope: WHERE user_id = ? ensures a user can only mark their own notifications.
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1, read_at = NOW()
        WHERE notification_id = ? AND user_id = ? AND is_read = 0
    ");
    $stmt->execute([$notification_id, $user_id]);

    if ($stmt->rowCount() === 0) {
        // Either already read or does not belong to this user — both are safe non-errors
        echo json_encode(['success' => true, 'message' => 'Already marked as read.']);
        exit;
    }

    // Return updated unread count so Flutter can update the badge without a full re-fetch
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $cStmt->execute([$user_id]);
    $unread_count = (int)$cStmt->fetchColumn();

    echo json_encode([
        'success'      => true,
        'message'      => 'Notification marked as read.',
        'unread_count' => $unread_count,
    ]);
} catch (PDOException $e) {
    error_log('notifications/mark_read: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
