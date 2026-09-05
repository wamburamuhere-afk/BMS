<?php
/**
 * actions/superadmin_tenant_users.php — on-demand read-only directory of one
 * tenant's staff accounts.
 *
 * The second deliberate, narrow exception to "the superadmin panel never
 * opens a tenant's own database" — see the full reasoning in
 * core/tenant_admin.php::tenantUserDirectory(), which this endpoint is a thin
 * wrapper around. Kept as its OWN endpoint, same discipline as
 * actions/superadmin_tenant_usage.php: the one code path that crosses this
 * boundary is easy to find, audit, and remove independently of the other.
 *
 * Same guard order as every other superadmin action: host, session, POST,
 * CSRF. GET is deliberately not supported — see superadmin_tenant_usage.php's
 * own note on why a GET-cacheable endpoint that opens a tenant's database on
 * every hit defeats the point of "on demand".
 */
require_once __DIR__ . '/../core/tenant_admin.php';
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
if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

$users = tenantUserDirectory($tenantId);

if ($users === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Could not read the user directory for this tenant right now.']);
    exit;
}

echo json_encode(['success' => true, 'users' => $users, 'count' => count($users)]);
