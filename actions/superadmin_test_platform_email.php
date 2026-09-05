<?php
/**
 * actions/superadmin_test_platform_email.php — send a test email using either
 * the values currently on screen (unsaved) or, if the password field was left
 * blank, the already-saved encrypted one. Mirrors api/test_email_config.php's
 * SMTP-config-test branch, but through the superadmin guard chain and
 * platform_settings instead of a tenant's system_settings.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/platform_settings.php';
require_once __DIR__ . '/../core/mailer.php';
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

$host = trim((string)($_POST['smtp_host'] ?? ''));
$port = (int)($_POST['smtp_port'] ?? 587);
$user = trim((string)($_POST['smtp_username'] ?? ''));
$pass = (string)($_POST['smtp_password'] ?? '');
$enc  = trim((string)($_POST['smtp_encryption'] ?? 'tls'));
$fromEmail = trim((string)($_POST['from_email'] ?? ''));
$fromName  = trim((string)($_POST['from_name'] ?? ''));
$to        = trim((string)($_POST['send_to'] ?? ''));

if ($host === '' || $user === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter SMTP Host and Username before testing.']);
    exit;
}

// Password left blank on screen → fall back to the already-saved one, same
// courtesy the tenant-side test button already gives (api/test_email_config.php).
if ($pass === '') {
    $encPass = getPlatformSetting('smtp_password_enc');
    $pass = $encPass !== '' ? (decryptSecret($encPass) ?? '') : '';
}

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $to = $fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? $fromEmail : $user;
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No valid recipient — provide "Send test to" or a From Email.']);
    exit;
}

$subject = 'BMS Platform: SMTP configuration test';
$message = "This is a test email from the BMS superadmin panel to verify the platform's SMTP settings.\n\n"
         . "If you received this, platform-originated email (welcome messages, announcements) is configured correctly.\n\n"
         . "Host: $host\nPort: $port\nEncryption: " . ($enc !== '' ? strtoupper($enc) : 'None');
$bodyHtml = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$ok = sendEmail($to, $subject, $bodyHtml, [
    'wrap'       => true,
    'wrap_brand' => getPlatformSetting('platform_name', 'BMS Platform'),
    'smtp' => [
        'host' => $host, 'port' => $port, 'username' => $user, 'password' => $pass,
        'encryption' => $enc, 'from_email' => $fromEmail, 'from_name' => $fromName,
    ],
    'from_email' => $fromEmail,
    'from_name'  => $fromName,
]);

if ($ok) {
    echo json_encode(['success' => true, 'message' => "Test email sent to $to. Check the inbox (and spam folder)."]);
} else {
    echo json_encode(['success' => false, 'message' => 'Send failed: ' . mailer_last_error()]);
}
