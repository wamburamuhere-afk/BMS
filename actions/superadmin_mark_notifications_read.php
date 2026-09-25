<?php
/**
 * actions/superadmin_mark_notifications_read.php
 * Marks all superadmin notifications as read.
 * Called via AJAX when the bell dropdown is opened.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/superadmin_notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}

if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}

csrf_check();
markAllSaNotificationsRead();
echo json_encode(['success' => true]);
