<?php
/**
 * actions/superadmin_ai.php — Platform AI (platform_ai_plan.md).
 * save_settings · test · set_policy. Control database only.
 * Guard order mirrors actions/superadmin_support_settings.php.
 * The API key is never echoed back, logged, or written to the audit trail.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../core/ai_service.php';

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

$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'save_settings':
            $r = aiPlatformSave($_POST, (int)$me['id']);
            if (!$r['ok']) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => reset($r['errors']), 'errors' => $r['errors']]);
                exit;
            }
            $s = aiPlatformSettings();
            logTenantAdminAction(null, null, 'ai_settings', sprintf(
                'Updated platform AI settings (enabled=%s, provider=%s, model=%s, tenant cap=%s, global cap=%s, key changed=%s)',
                $s['enabled'] ? 'yes' : 'no', $s['provider'], $s['model'], $s['tenant_cap'], $s['global_cap'], $r['key_changed'] ? 'yes' : 'no'
            ));
            echo json_encode(['success' => true, 'message' => 'AI settings saved.', 'has_key' => $s['has_key']]);
            exit;

        case 'test':
            $r = aiPlatformTest((int)$me['id']);
            logTenantAdminAction(null, null, 'ai_test', $r['ok'] ? 'Platform AI connection test passed' : 'Platform AI connection test failed');
            echo json_encode(['success' => $r['ok'], 'message' => $r['message']]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
            exit;
    }
} catch (Throwable $e) {
    error_log('superadmin_ai: ' . aiSanitizeError($e->getMessage()));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The change could not be saved.']);
}
