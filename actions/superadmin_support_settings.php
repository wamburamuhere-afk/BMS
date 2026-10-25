<?php
/**
 * actions/superadmin_support_settings.php — save BJP's support settings
 * (support_center_plan.md D9). Control database only.
 * Guard order mirrors actions/superadmin_platform_settings.php.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/support.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$me = currentSuperadmin();
if ($me === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();

if ((string)($_POST['action'] ?? '') !== 'save_support') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$r = supportValidateSettings($_POST);
if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => reset($r['errors']), 'errors' => $r['errors']]);
    exit;
}

try {
    foreach ($r['values'] as $k => $v) {
        setPlatformSetting($k, $v, (int)$me['id']);
    }
} catch (Throwable $e) {
    error_log('superadmin_support_settings: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The settings could not be saved.']);
    exit;
}

logTenantAdminAction(null, null, 'support_settings', 'Updated support settings');
echo json_encode(['success' => true, 'message' => 'Support settings saved.']);
