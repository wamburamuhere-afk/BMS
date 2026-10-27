<?php
/**
 * actions/register_tenant.php — POST handler for public self-registration.
 *
 * Public and unauthenticated. Every guard that matters lives in
 * core/tenant_registration.php (gate, honeypot, throttle, validation) so this
 * file stays a thin, auditable transport layer.
 *
 * Provisioning takes a few seconds — it creates a database, applies ~300 tables
 * and seeds defaults — so the request is given room to finish rather than dying
 * halfway and leaving the visitor unsure whether they have an account.
 */
require_once __DIR__ . '/../core/tenant_registration.php';
require_once __DIR__ . '/../helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

csrf_check();   // §21 — exits 419 on mismatch

// Signup must never be offered from inside an existing tenant's address: a
// visitor on kampunia.example.com is a customer of that tenant, not a prospect.
$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found.']);
    exit;
}

// Provisioning is slow by nature; don't let the default limit truncate it and
// abandon a half-built tenant to the rollback path.
@set_time_limit(120);
@ignore_user_abort(true);

// Company logo is optional. Validated here — cheaply, before any throttle
// slot or database work is spent — so a bad file type never eats one of the
// visitor's limited registration attempts.
$logoTmpPath  = null;
$logoExtension = null;
if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['company_logo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Could not upload the logo. Please try again.']);
        exit;
    }
    if ($_FILES['company_logo']['size'] > 2 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Logo must be 2MB or smaller.']);
        exit;
    }
    $ext = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid logo file type. Only JPG, PNG and GIF are allowed.']);
        exit;
    }
    $logoTmpPath   = $_FILES['company_logo']['tmp_name'];
    $logoExtension = $ext;
}

if (empty($_POST['accept_terms'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please accept the Terms of Service and Privacy Policy.']);
    exit;
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

// Progress record for actions/register_status.php: if the browser loses this
// request (timeout, dropped connection) while provisioning carries on, the page
// asks there instead of reporting a failure that did not happen. The session is
// released during provisioning so those status checks are not blocked by it.
$regToken = (string)($_POST['reg_token'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $regToken)) $regToken = '';
$saveRegState = function (array $state) use ($regToken) {
    if ($regToken === '') return;
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    foreach ($_SESSION['bms_reg'] ?? [] as $k => $v) {
        if (($v['t'] ?? 0) < time() - 3600) unset($_SESSION['bms_reg'][$k]);
    }
    $_SESSION['bms_reg'][$regToken] = $state + ['t' => time()];
    session_write_close();
};
$saveRegState(['state' => 'running']);
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$result = registerTenant([
    'company_name'             => $_POST['company_name'] ?? '',
    'subdomain'                => $_POST['subdomain'] ?? '',
    'owner_username'           => $_POST['owner_username'] ?? '',
    'owner_phone'              => $_POST['owner_phone'] ?? '',
    'business_type'            => $_POST['business_type'] ?? '',
    'product_key'              => $_POST['product_key'] ?? '',
    'owner_email'              => $_POST['owner_email'] ?? '',
    'owner_password'           => $_POST['owner_password'] ?? '',
    'owner_password_confirm'   => $_POST['owner_password_confirm'] ?? '',
    'owner_first_name'         => $_POST['owner_first_name'] ?? '',
    'owner_last_name'          => $_POST['owner_last_name'] ?? '',
    'company_physical_address' => $_POST['company_physical_address'] ?? '',
    'company_postal_address'   => $_POST['company_postal_address'] ?? '',
    'country'                  => $_POST['country']       ?? '',
    'industry'                 => $_POST['industry']      ?? '',
    'company_size'             => $_POST['company_size']  ?? '',
    'logo_tmp_path'            => $logoTmpPath,
    'logo_extension'           => $logoExtension,
    'plan_key'                 => $_POST['plan_key']  ?? '',   // re-checked on the server
    'plan_term'                => $_POST['plan_term'] ?? '',
    'website'                  => $_POST['website'] ?? '',   // honeypot
    'defer_emails'             => true,   // sent by actions/register_after.php
], $ip);

if (!$result['ok']) {
    $saveRegState(['state' => 'failed', 'message' => $result['error']]);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

$response = [
    'success'   => true,
    'message'   => 'Your account is ready.',
    'subdomain' => $result['subdomain'],
    'login_url' => $result['login_url'],
    'username'  => $result['username'] ?? '',
    'pending_approval' => !empty($result['pending_approval']),
];
// The emails wait in this visitor's session for actions/register_after.php.
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($result['deferred_emails'])) $_SESSION['bms_reg_mail'][] = $result['deferred_emails'];
session_write_close();
$saveRegState(['state' => 'done', 'response' => $response]);

echo json_encode($response);
