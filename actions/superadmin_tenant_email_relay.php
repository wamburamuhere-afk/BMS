<?php
/**
 * actions/superadmin_tenant_email_relay.php
 *
 * Read or write the `use_platform_email` flag in a specific tenant's
 * system_settings. Same guard discipline as superadmin_tenant_profile.php.
 *
 * POST body:
 *   tenant_id  int
 *   action     'get' | 'set'
 *   value      '1' | '0'   (required when action = 'set')
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
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

if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your session has ended. Please sign in again.']);
    exit;
}

csrf_check();

$tenantId = (int)($_POST['tenant_id'] ?? 0);
$action   = $_POST['action'] ?? 'get';
$value    = $_POST['value'] ?? '';

if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

if (!in_array($action, ['get', 'set'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

if ($action === 'set' && !in_array($value, ['0', '1'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Value must be 0 or 1.']);
    exit;
}

try {
    $st = getControlPdo()->prepare(
        "SELECT db_host, db_name, db_username, db_password_encrypted, status FROM tenants WHERE id = ? LIMIT 1"
    );
    $st->execute([$tenantId]);
    $t = $st->fetch();

    if (!$t || $t['status'] === 'deleted') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tenant not found or deleted.']);
        exit;
    }

    $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
    if ($pw === null) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not decrypt tenant credentials.']);
        exit;
    }

    $tPdo = new PDO(
        'mysql:host=' . $t['db_host'] . ';dbname=' . $t['db_name'] . ';charset=utf8mb4',
        $t['db_username'], $pw,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );

    if ($action === 'get') {
        $st2 = $tPdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'use_platform_email' LIMIT 1");
        $st2->execute();
        $row = $st2->fetch(PDO::FETCH_ASSOC);
        $current = $row ? $row['setting_value'] : '1'; // default ON
        echo json_encode(['success' => true, 'value' => $current]);
    } else {
        $tPdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public)
            VALUES ('use_platform_email', ?, 'email', 0)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute([$value]);
        echo json_encode(['success' => true, 'value' => $value]);
    }
} catch (Throwable $e) {
    error_log('superadmin_tenant_email_relay(' . $tenantId . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update tenant email relay setting.']);
}
