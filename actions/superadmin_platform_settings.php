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
    // ── Provider ─────────────────────────────────────────────────────────────
    $provider = strtolower(trim((string)($_POST['email_provider'] ?? 'own')));
    if (!in_array($provider, ['own', 'ses', 'mailgun', 'sendgrid'], true)) {
        $provider = 'own';
    }

    // For managed providers, derive host/port/enc server-side — never trust POST
    $sesRegion = 'us-east-1';
    if ($provider === 'ses') {
        $sesRegion = trim((string)($_POST['ses_region'] ?? 'us-east-1'));
        if (!preg_match('/^[a-z][a-z0-9-]{2,29}$/', $sesRegion)) {
            $sesRegion = 'us-east-1';
        }
    }

    $providerPresets = [
        'ses'      => ['host' => "email-smtp.{$sesRegion}.amazonaws.com", 'port' => '587', 'enc' => 'tls'],
        'mailgun'  => ['host' => 'smtp.mailgun.org',                      'port' => '587', 'enc' => 'tls'],
        'sendgrid' => ['host' => 'smtp.sendgrid.net',                     'port' => '587', 'enc' => 'tls'],
    ];

    if ($provider !== 'own') {
        // Managed provider: host/port/enc are fixed
        $preset = $providerPresets[$provider];
        $host = $preset['host'];
        $port = $preset['port'];
        $enc  = $preset['enc'];
    } else {
        // Own server: read from POST and validate
        $host = trim((string)($_POST['smtp_host'] ?? ''));
        $port = trim((string)($_POST['smtp_port'] ?? ''));
        $enc  = strtolower(trim((string)($_POST['smtp_encryption'] ?? 'tls')));

        if ($host === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'SMTP Host is required for Own Server.']);
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
    }

    // ── Credentials (always from POST regardless of provider) ────────────────
    $user = trim((string)($_POST['smtp_username'] ?? ''));
    // SendGrid requires username = 'apikey' (enforced here too)
    if ($provider === 'sendgrid') {
        $user = 'apikey';
    }
    $pass      = (string)($_POST['smtp_password'] ?? '');   // blank = keep existing
    $fromEmail = trim((string)($_POST['from_email'] ?? ''));
    $fromName  = trim((string)($_POST['from_name'] ?? ''));

    if ($user === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Username / Access Key ID is required.']);
        exit;
    }
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid From Email address.']);
        exit;
    }

    // ── Persist ───────────────────────────────────────────────────────────────
    $uid = (int)$me['id'];
    setPlatformSetting('email_provider', $provider,   $uid);
    setPlatformSetting('smtp_host',       $host,       $uid);
    setPlatformSetting('smtp_port',       $port,       $uid);
    setPlatformSetting('smtp_username',   $user,       $uid);
    setPlatformSetting('smtp_encryption', $enc,        $uid);
    setPlatformSetting('from_email',      $fromEmail,  $uid);
    setPlatformSetting('from_name',       $fromName,   $uid);
    if ($provider === 'ses') {
        setPlatformSetting('ses_region', $sesRegion, $uid);
    }
    if ($pass !== '') {
        setPlatformSetting('smtp_password_enc', encryptSecret($pass), $uid);
    }

    logTenantAdminAction(null, null, 'platform_settings', "Updated email/SMTP settings (provider: {$provider})");
    echo json_encode(['success' => true, 'message' => 'Email settings updated.']);
    exit;
}

if ($action === 'save_provisioning') {
    // tenant_module_control_plan.md §5.1 — governs ONLY the unattended,
    // self-registration path (register.php). A superadmin creating a tenant
    // by hand (tenant_new.php) always explicitly picks a plan there, or
    // leaves it blank for "everything on", regardless of this switch.
    $mode = (string)($_POST['provisioning_mode'] ?? '');
    if (!in_array($mode, ['all', 'none'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => "Choose 'all' or 'none'."]);
        exit;
    }
    setPlatformSetting('tenant_default_provisioning', $mode, (int)$me['id']);
    logTenantAdminAction(null, null, 'platform_settings', "Self-registration starting modules set to '$mode'");
    echo json_encode(['success' => true, 'message' => 'Self-registration setting updated.']);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
