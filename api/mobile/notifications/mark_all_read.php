<?php
// scope-audit: skip — row-level scoped to user_id; user can only act on their own notifications
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

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
$user_id = (int)$_SESSION['user_id'];
$action  = trim($_POST['action'] ?? 'mark_all_read');

if (!in_array($action, ['mark_all_read', 'clear_read'], true)) {
    echo json_encode(['success' => false, 'message' => 'action must be mark_all_read or clear_read']);
    exit;
}

try {
    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        $affected = $stmt->rowCount();
        $message  = $affected > 0
            ? "All {$affected} notification(s) marked as read."
            : 'No unread notifications.';
    } else {
        // clear_read: permanently delete already-read notifications for this user
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
        $stmt->execute([$user_id]);
        $affected = $stmt->rowCount();
        $message  = $affected > 0
            ? "Cleared {$affected} read notification(s)."
            : 'No read notifications to clear.';
    }

    // Return updated unread count so Flutter can update the badge without a full re-fetch
    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $cStmt->execute([$user_id]);
    $unread_count = (int)$cStmt->fetchColumn();

    echo json_encode([
        'success'      => true,
        'message'      => $message,
        'affected'     => $affected,
        'unread_count' => $unread_count,
    ]);
} catch (PDOException $e) {
    error_log('notifications/mark_all_read: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
