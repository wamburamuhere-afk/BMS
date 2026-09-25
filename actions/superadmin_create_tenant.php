<?php
/**
 * actions/superadmin_create_tenant.php — register a company from the panel.
 *
 * Guards mirror actions/superadmin_tenant_action.php: correct host, signed-in
 * operator, CSRF, POST only — all before any work.
 *
 * Provisioning creates a database and a MySQL user, so this is the most
 * privileged thing the panel can do. It is therefore gated by an authenticated
 * operator session and nothing else: no honeypot, no IP throttle, and it works
 * while public self-registration is switched off. The reasoning for each of
 * those is in createTenantAsOperator()'s docblock.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/plans.php';
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

// Provisioning restores a full schema and seeds defaults; on a slow host that
// is comfortably longer than the default limit, and a timeout half-way through
// would leave the operator with no idea whether the company exists.
@set_time_limit(180);

$r = createTenantAsOperator([
    'company_name'           => (string)($_POST['company_name'] ?? ''),
    'subdomain'              => (string)($_POST['subdomain'] ?? ''),
    'owner_email'            => (string)($_POST['owner_email'] ?? ''),
    'owner_password'         => (string)($_POST['owner_password'] ?? ''),
    'owner_password_confirm' => (string)($_POST['owner_password_confirm'] ?? ''),
    'owner_first_name'       => (string)($_POST['owner_first_name'] ?? ''),
    'owner_last_name'        => (string)($_POST['owner_last_name'] ?? ''),
    'owner_phone'            => (string)($_POST['owner_phone']     ?? ''),
    'status'                 => (string)($_POST['status'] ?? 'active'),
    'plan_id'                => (string)($_POST['plan_id'] ?? ''),
    'country'                => (string)($_POST['country']       ?? ''),
    'industry'               => (string)($_POST['industry']      ?? ''),
    'company_size'           => (string)($_POST['company_size']  ?? ''),
    'trial_ends_at'          => !empty($_POST['trial_ends_at'])
                                   ? date('Y-m-d 23:59:59', strtotime((string)$_POST['trial_ends_at']))
                                   : '',
    // Optional company profile fields
    'phone'               => (string)($_POST['phone']               ?? ''),
    'email'               => (string)($_POST['email']               ?? ''),
    'address'             => (string)($_POST['address']             ?? ''),
    'website'             => (string)($_POST['website']             ?? ''),
    'tin'                 => (string)($_POST['tin']                 ?? ''),
    'vrn'                 => (string)($_POST['vrn']                 ?? ''),
    'billing_cycle'       => (string)($_POST['billing_cycle']       ?? ''),
    'billing_amount_tzs'  => (string)($_POST['billing_amount_tzs']  ?? ''),
    'payment_status'      => (string)($_POST['payment_status']      ?? ''),
]);

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

echo json_encode([
    'success'   => true,
    'message'   => 'Company created. Its database, database user and owner account are ready.',
    'tenant_id' => $r['tenant_id'],
    'subdomain' => $r['subdomain'],
    'login_url' => $r['login_url'],
]);
