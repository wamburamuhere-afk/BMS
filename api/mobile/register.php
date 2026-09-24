<?php
/**
 * api/mobile/register.php — Public self-registration for the Flutter app.
 *
 * POST — no auth required (public endpoint).
 *
 * Creates a new tenant, provisions its database, and returns a Bearer token
 * in the same response so the app never needs a separate login step.
 *
 * Reachable ONLY from the platform root domain (same rule as register.php).
 * A request coming from an existing tenant's subdomain gets 404.
 *
 * Fields:
 *   company_name            required
 *   owner_phone             required  — login credential (username)
 *   owner_password          required  — min 8 chars, letter + digit
 *   owner_password_confirm  required
 *   owner_first_name        optional
 *   owner_last_name         optional
 *   owner_email             optional
 *   company_physical_address optional
 *   company_postal_address  optional
 *   subdomain               optional  — auto-generated from company_name if omitted
 *   device_name             optional  — label stored on the issued token
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/tenant_registration.php';
require_once __DIR__ . '/../../core/tenant_crypto.php';
require_once __DIR__ . '/../../core/control_db.php';
require_once __DIR__ . '/../../helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Method guard ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Only accept requests from the platform root domain ───────────────────────
// A visitor on acme.bms.bjptechnologies.co.tz is that company's customer, not
// a new prospect — registering from there would create a second tenant under
// an ambiguous hostname.
$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

// ── Provisioning is slow; don't let the default limit truncate it ─────────────
@set_time_limit(120);
@ignore_user_abort(true);

// ── Read input — accept both form-encoded and JSON body ──────────────────────
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType === 'application/json') {
    $body = (array)json_decode(file_get_contents('php://input'), true);
} else {
    $body = $_POST;
}

$company  = trim((string)($body['company_name']  ?? ''));
$phone    = preg_replace('/\s+/', '', (string)($body['owner_phone'] ?? ''));
$pw       = (string)($body['owner_password']         ?? '');
$pwConf   = (string)($body['owner_password_confirm'] ?? '');
$firstName = trim((string)($body['owner_first_name']  ?? ''));
$lastName  = trim((string)($body['owner_last_name']   ?? ''));
$email     = trim((string)($body['owner_email']       ?? ''));
$physAddr  = trim((string)($body['company_physical_address'] ?? ''));
$postAddr  = trim((string)($body['company_postal_address']   ?? ''));
$subdomain = strtolower(trim((string)($body['subdomain'] ?? '')));
$deviceName = trim((string)($body['device_name'] ?? 'Flutter App'));

// ── Auto-generate subdomain from company name if not supplied ────────────────
if ($subdomain === '') {
    $subdomain = mobileAutoSubdomain($company);
}

// ── Provision the tenant ─────────────────────────────────────────────────────
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

$result = registerTenant([
    'company_name'             => $company,
    'subdomain'                => $subdomain,
    'owner_phone'              => $phone,
    'owner_email'              => $email,
    'owner_password'           => $pw,
    'owner_password_confirm'   => $pwConf,
    'owner_first_name'         => $firstName,
    'owner_last_name'          => $lastName,
    'company_physical_address' => $physAddr,
    'company_postal_address'   => $postAddr,
    'logo_tmp_path'            => null,  // logo can be added later via company settings
    'logo_extension'           => null,
    'website'                  => '',   // honeypot — always empty for the API
], $ip);

if (!$result['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

// ── Connect to the newly-provisioned tenant DB and issue a Bearer token ───────
try {
    $cpdo   = getControlPdo();
    $tenant = $cpdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1")
                   ->execute([$result['tenant_id']]) ?: null;
    $tenant = $cpdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $tenant->execute([$result['tenant_id']]);
    $tenantRow = $tenant->fetch(PDO::FETCH_ASSOC);

    if (!$tenantRow) {
        throw new RuntimeException('Tenant row not found after provisioning.');
    }

    $dbPass = decryptTenantSecret((string)$tenantRow['db_password_encrypted']);
    if ($dbPass === null) {
        throw new RuntimeException('Could not decrypt tenant credentials.');
    }

    $tpdo = new PDO(
        'mysql:host=' . $tenantRow['db_host'] . ';dbname=' . $tenantRow['db_name'] . ';charset=utf8mb4',
        $tenantRow['db_username'],
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $tpdo->exec("SET time_zone = '+03:00'");

    // The provisioner creates the owner as the sole admin (role_id = 1).
    $userStmt = $tpdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $userStmt->execute([$phone]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Fallback: find any role_id = 1 user (phone may have been normalised)
        $userStmt = $tpdo->prepare("SELECT * FROM users WHERE role_id = 1 LIMIT 1");
        $userStmt->execute();
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        throw new RuntimeException('Admin user not found in provisioned tenant.');
    }

    // Issue a non-expiring Bearer token (same pattern as api/mobile/login.php)
    $token = bin2hex(random_bytes(32));

    $tpdo->prepare("
        INSERT INTO mobile_tokens (token, user_id, device_name, expires_at)
        VALUES (?, ?, ?, NULL)
    ")->execute([$token, $user['user_id'], $deviceName]);

    $tpdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")
         ->execute([$user['user_id']]);

    // Read back company settings seeded by the provisioner
    $settingStmt = $tpdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?, ?, ?, ?)");
    $settingStmt->execute(['company_name', 'company_logo', 'company_currency', 'company_address', 'company_phone']);
    $settings = [];
    while ($row = $settingStmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    echo json_encode([
        'success'    => true,
        'message'    => 'Account created successfully. Welcome to BMS!',
        'token'      => $token,
        'expires_at' => null,
        'tenant_id'  => (int)$result['tenant_id'],
        'subdomain'  => $result['subdomain'],
        'tenant_url' => $result['login_url'],  // https://{subdomain}.bms.bjptechnologies.co.tz/login
        'user'       => [
            'id'         => (int)$user['user_id'],
            'name'       => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'first_name' => $user['first_name'] ?? '',
            'last_name'  => $user['last_name']  ?? '',
            'role'       => $user['user_role']  ?? 'admin',
            'role_id'    => (int)($user['role_id'] ?? 1),
            'phone'      => $user['username'] ?? $phone,
            'email'      => $user['email']    ?? $email,
            'currency'   => $settings['company_currency'] ?? 'TZS',
        ],
        'company' => [
            'name'     => $settings['company_name']     ?? $company,
            'logo_url' => $settings['company_logo']     ?? '',
            'currency' => $settings['company_currency'] ?? 'TZS',
            'address'  => $settings['company_address']  ?? $physAddr,
            'phone'    => $settings['company_phone']    ?? $phone,
        ],
    ]);

} catch (Throwable $e) {
    error_log('api/mobile/register.php post-provisioning error: ' . $e->getMessage());
    // Tenant was provisioned but token issuance failed. The owner can still
    // log in via api/mobile/login.php — don't report this as a registration failure.
    echo json_encode([
        'success'    => true,
        'message'    => 'Account created. Please log in with your phone and password.',
        'token'      => null,
        'expires_at' => null,
        'tenant_id'  => (int)$result['tenant_id'],
        'subdomain'  => $result['subdomain'],
        'tenant_url' => $result['login_url'],
    ]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build a URL-safe subdomain slug from a company name, then find the first
 * variant that is available in the tenants registry.
 *
 * "Acme Shop Ltd"  →  "acme-shop-ltd"
 * If taken         →  "acme-shop-ltd-2", "acme-shop-ltd-3", …
 */
function mobileAutoSubdomain(string $companyName): string
{
    // Transliterate accented characters, then keep only a-z 0-9 -
    $base = strtolower($companyName);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    $base = preg_replace('/-{2,}/', '-', $base);
    $base = substr($base, 0, 32);
    $base = rtrim($base, '-');

    if ($base === '' || mb_strlen($base) < 3) {
        $base = 'company';
    }

    // tenantSubdomainAvailable() + tenantSubdomainError() guard reserved words
    // and format rules as well as registry uniqueness.
    if (!tenantSubdomainError($base) && tenantSubdomainAvailable($base)) {
        return $base;
    }

    // Try numeric suffixes up to -99
    $truncated = substr($base, 0, 28);
    for ($i = 2; $i <= 99; $i++) {
        $candidate = $truncated . '-' . $i;
        if (!tenantSubdomainError($candidate) && tenantSubdomainAvailable($candidate)) {
            return $candidate;
        }
    }

    // Last resort: 6-char hex suffix
    return substr($base, 0, 25) . '-' . bin2hex(random_bytes(3));
}
