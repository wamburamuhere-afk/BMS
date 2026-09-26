<?php
/**
 * api/mobile/register.php — Public self-registration for the Flutter app.
 *
 * POST — no auth required (public endpoint).
 *
 * Validates inputs and queues a registration_job in the control DB, then
 * returns in < 1 second. Actual tenant provisioning (DB creation, schema,
 * seeds — typically 60-120 s) runs in the background via
 * cron/process_registration_jobs.php.
 *
 * Response (HTTP 202):
 *   { success, status:"provisioning", job_id, username, subdomain,
 *     tenant_url, message, poll_url, poll_seconds }
 *
 * Poll GET api/mobile/register_status.php?job_id=<job_id> every 5 s.
 * When status = "ready" the response includes the full login payload + token.
 *
 * Fields:
 *   company_name            required
 *   owner_phone             required  — becomes the BMS login username
 *   owner_password          required  — min 8 chars, letter + digit
 *   owner_password_confirm  required
 *   owner_first_name        optional
 *   owner_last_name         optional
 *   owner_email             optional
 *   company_physical_address optional
 *   company_postal_address  optional
 *   subdomain               optional  — auto-generated from company_name if omitted
 *   device_name             optional  — label stored on the issued Bearer token
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/tenant_registration.php';
require_once __DIR__ . '/../../core/tenant_crypto.php';
require_once __DIR__ . '/../../core/control_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ── Only accept requests from the platform root domain ───────────────────────
$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

// ── Self-registration gate ────────────────────────────────────────────────────
if (!selfRegistrationOpen()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => selfRegistrationClosedReason() ?? 'Registration is currently closed.']);
    exit;
}

// ── Read input ────────────────────────────────────────────────────────────────
$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType === 'application/json') {
    $body = (array)json_decode(file_get_contents('php://input'), true);
} else {
    $body = $_POST;
}

$company    = trim((string)($body['company_name']  ?? ''));
$phone      = preg_replace('/\s+/', '', (string)($body['owner_phone'] ?? ''));
$pw         = (string)($body['owner_password']         ?? '');
$pwConf     = (string)($body['owner_password_confirm'] ?? '');
$firstName  = trim((string)($body['owner_first_name']  ?? ''));
$lastName   = trim((string)($body['owner_last_name']   ?? ''));
$email      = trim((string)($body['owner_email']       ?? ''));
$physAddr   = trim((string)($body['company_physical_address'] ?? ''));
$postAddr   = trim((string)($body['company_postal_address']   ?? ''));
$subdomain  = strtolower(trim((string)($body['subdomain'] ?? '')));
$deviceName = trim((string)($body['device_name'] ?? 'Flutter App'));
$ip         = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// ── Input validation (fast — no DB) ──────────────────────────────────────────
if ($company === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Company name is required.']);
    exit;
}
if ($phone === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
    exit;
}
if (mb_strlen($pw) < 8 || !preg_match('/[a-zA-Z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
    logRegistrationAttempt($ip, $phone, null, 'rejected', 'weak password');
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters and contain at least one letter and one digit.']);
    exit;
}
if ($pw !== $pwConf) {
    logRegistrationAttempt($ip, $phone, null, 'rejected', 'password mismatch');
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// ── Rate limit ────────────────────────────────────────────────────────────────
$throttleError = registrationThrottleCheck($ip);
if ($throttleError) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => $throttleError]);
    exit;
}

// ── Subdomain ─────────────────────────────────────────────────────────────────
if ($subdomain === '') {
    $subdomain = regMobileAutoSubdomain($company);
} else {
    $subErr = tenantSubdomainError($subdomain);
    if ($subErr) {
        logRegistrationAttempt($ip, $phone, $subdomain, 'rejected', $subErr);
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $subErr]);
        exit;
    }
    if (!tenantSubdomainAvailable($subdomain)) {
        logRegistrationAttempt($ip, $phone, $subdomain, 'rejected', 'subdomain taken');
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'That subdomain is already taken. Please choose another.']);
        exit;
    }
}

// ── Duplicate checks (DB) ─────────────────────────────────────────────────────
$cpdo = getControlPdo();

// One account per phone number
$dup = $cpdo->prepare("SELECT id FROM tenants WHERE owner_phone = ? AND status != 'deleted' LIMIT 1");
$dup->execute([$phone]);
if ($dup->fetchColumn()) {
    logRegistrationAttempt($ip, $phone, $subdomain, 'rejected', 'duplicate phone');
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'An account with this phone number is already registered. Please log in instead.']);
    exit;
}

// Don't re-queue if a pending job already exists for this phone
$existingJob = $cpdo->prepare("
    SELECT job_id, subdomain FROM registration_jobs
    WHERE owner_phone = ? AND status IN ('pending','provisioning')
    ORDER BY created_at DESC LIMIT 1
");
$existingJob->execute([$phone]);
if ($row = $existingJob->fetch(PDO::FETCH_ASSOC)) {
    $base      = tenantBaseDomain() ?? '';
    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $tenantUrl = $scheme . '://' . $row['subdomain'] . '.' . $base . '/login';
    http_response_code(202);
    echo json_encode([
        'success'      => true,
        'status'       => 'provisioning',
        'job_id'       => $row['job_id'],
        'message'      => 'Your account is already being set up. Please wait about 1 minute then log in.',
        'username'     => $phone,
        'subdomain'    => $row['subdomain'],
        'tenant_url'   => $tenantUrl,
        'poll_url'     => 'GET api/mobile/register_status.php?job_id=' . $row['job_id'],
        'poll_seconds' => 5,
    ]);
    exit;
}

// Don't queue same subdomain twice (prevents provisioning race between two users)
$dupSub = $cpdo->prepare("
    SELECT job_id FROM registration_jobs WHERE subdomain = ? AND status IN ('pending','provisioning') LIMIT 1
");
$dupSub->execute([$subdomain]);
if ($dupSub->fetchColumn()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'That subdomain is already taken. Please choose another.']);
    exit;
}

// ── Encrypt the password for the background worker ───────────────────────────
$passEnc = encryptTenantSecret($pw);
if ($passEnc === null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server configuration error. Please try again later.']);
    exit;
}

// ── Build tenant URL (stored now so the status endpoint can return it) ────────
$base      = tenantBaseDomain() ?? '';
$scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$tenantUrl = $scheme . '://' . $subdomain . '.' . $base . '/login';

// ── Insert job ────────────────────────────────────────────────────────────────
$jobId = bin2hex(random_bytes(32));
$cpdo->prepare("
    INSERT INTO registration_jobs
        (job_id, status, company_name, subdomain, owner_phone, owner_pass_enc,
         owner_first_name, owner_last_name, owner_email,
         phys_address, post_address, device_name, ip_address, tenant_url)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
")->execute([
    $jobId, 'pending', $company, $subdomain, $phone, $passEnc,
    $firstName, $lastName, $email,
    $physAddr, $postAddr, $deviceName, $ip, $tenantUrl,
]);

logRegistrationAttempt($ip, $phone, $subdomain, 'success', 'queued job_id=' . $jobId);

// ── Kick the background worker (best-effort; cron is the reliable fallback) ───
regTriggerWorker();

// ── Respond immediately ───────────────────────────────────────────────────────
http_response_code(202);
echo json_encode([
    'success'      => true,
    'status'       => 'provisioning',
    'job_id'       => $jobId,
    'message'      => 'Account created! Your BMS system is being set up — this usually takes about 1 minute.',
    'username'     => $phone,
    'subdomain'    => $subdomain,
    'tenant_url'   => $tenantUrl,
    'note'         => 'Use your phone number as your username when you log in.',
    'poll_url'     => 'GET api/mobile/register_status.php?job_id=' . $jobId,
    'poll_seconds' => 5,
]);
exit;

// ── Helpers ───────────────────────────────────────────────────────────────────

function regTriggerWorker(): void
{
    $script = realpath(__DIR__ . '/../../cron/process_registration_jobs.php');
    if (!$script || !is_file($script)) return;
    $php = PHP_BINARY;
    if (PHP_OS_FAMILY === 'Windows') {
        @pclose(@popen('start /b "" "' . $php . '" "' . $script . '" >NUL 2>&1', 'r'));
    } else {
        @exec('"' . $php . '" "' . $script . '" > /dev/null 2>&1 &');
    }
}

function regMobileAutoSubdomain(string $companyName): string
{
    $base = strtolower($companyName);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    $base = preg_replace('/-{2,}/', '-', $base);
    $base = substr($base, 0, 32);
    $base = rtrim($base, '-');
    if ($base === '' || mb_strlen($base) < 3) $base = 'company';

    if (!tenantSubdomainError($base) && tenantSubdomainAvailable($base)) {
        return $base;
    }
    $truncated = substr($base, 0, 28);
    for ($i = 2; $i <= 99; $i++) {
        $candidate = $truncated . '-' . $i;
        if (!tenantSubdomainError($candidate) && tenantSubdomainAvailable($candidate)) {
            return $candidate;
        }
    }
    return substr($base, 0, 25) . '-' . bin2hex(random_bytes(3));
}
