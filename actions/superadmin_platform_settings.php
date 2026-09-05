<?php
/**
 * actions/superadmin_platform_settings.php — save platform-wide branding/email
 * settings. Control database only.
 *
 * Guard order mirrors actions/superadmin_tenant_quotas.php exactly: correct
 * host, signed-in operator, POST only, CSRF.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/platform_settings.php';
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

$action = (string)($_POST['action'] ?? '');

if ($action === 'save_branding') {
    $platformName = trim((string)($_POST['platform_name'] ?? ''));
    if ($platformName === '' || mb_strlen($platformName) > 191) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a platform name (up to 191 characters).']);
        exit;
    }
    setPlatformSetting('platform_name', $platformName, (int)$me['id']);
    logTenantAdminAction(null, null, 'platform_settings', 'Updated branding: platform_name');
    echo json_encode(['success' => true, 'message' => 'Branding updated.']);
    exit;
}

if ($action === 'save_email') {
    $host = trim((string)($_POST['smtp_host'] ?? ''));
    $port = trim((string)($_POST['smtp_port'] ?? ''));
    $user = trim((string)($_POST['smtp_username'] ?? ''));
    $pass = (string)($_POST['smtp_password'] ?? '');            // blank = keep existing
    $enc  = strtolower(trim((string)($_POST['smtp_encryption'] ?? 'tls')));
    $fromEmail = trim((string)($_POST['from_email'] ?? ''));
    $fromName  = trim((string)($_POST['from_name'] ?? ''));

    if ($host === '' || $user === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'SMTP Host and Username are required.']);
        exit;
    }
    if ($port === '' || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid SMTP port (1-65535).']);
        exit;
    }
    if (!in_array($enc, ['tls', 'ssl', ''], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Encryption must be TLS, SSL, or None.']);
        exit;
    }
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid From Email address.']);
        exit;
    }

    setPlatformSetting('smtp_host', $host, (int)$me['id']);
    setPlatformSetting('smtp_port', $port, (int)$me['id']);
    setPlatformSetting('smtp_username', $user, (int)$me['id']);
    setPlatformSetting('smtp_encryption', $enc, (int)$me['id']);
    setPlatformSetting('from_email', $fromEmail, (int)$me['id']);
    setPlatformSetting('from_name', $fromName, (int)$me['id']);
    if ($pass !== '') {
        setPlatformSetting('smtp_password_enc', encryptSecret($pass), (int)$me['id']);
    }

    logTenantAdminAction(null, null, 'platform_settings', 'Updated email/SMTP settings');
    echo json_encode(['success' => true, 'message' => 'Email settings updated.']);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
