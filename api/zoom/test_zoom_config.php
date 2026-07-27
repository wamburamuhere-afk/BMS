<?php
/**
 * api/zoom/test_zoom_config.php — "Test connection" for the Zoom integration (admin only).
 * Requests a fresh OAuth access token from Zoom and reports success/failure.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/zoom_service.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!isAdmin())         { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin only']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

if (!zoomConfigured()) {
    echo json_encode(['success' => false, 'message' => 'Enable Zoom and set Account ID, Client ID and Client Secret first, then save before testing.']);
    exit;
}

// Force a fresh token instead of trusting a stale cache, so "Test Connection"
// always reflects the credentials currently saved.
$pdo->prepare("DELETE FROM system_settings WHERE setting_key IN ('zoom_access_token_enc','zoom_token_expires_at')")->execute();

$res = zoomGetAccessToken();

if ($res['success']) {
    echo json_encode(['success' => true, 'message' => 'Connected to Zoom successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => $res['message'] ?: 'Zoom did not respond.']);
}
