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

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

$result = registerTenant([
    'company_name'           => $_POST['company_name'] ?? '',
    'subdomain'              => $_POST['subdomain'] ?? '',
    'owner_email'            => $_POST['owner_email'] ?? '',
    'owner_password'         => $_POST['owner_password'] ?? '',
    'owner_password_confirm' => $_POST['owner_password_confirm'] ?? '',
    'owner_first_name'       => $_POST['owner_first_name'] ?? '',
    'owner_last_name'        => $_POST['owner_last_name'] ?? '',
    'website'                => $_POST['website'] ?? '',   // honeypot
], $ip);

if (!$result['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

echo json_encode([
    'success'   => true,
    'message'   => 'Your account is ready.',
    'subdomain' => $result['subdomain'],
    'login_url' => $result['login_url'],
]);
