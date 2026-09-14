<?php
/**
 * actions/superadmin_tenant_pos_simple_mode.php — view/set ONE tenant's POS
 * Simple Mode from the superadmin Tenant Detail page.
 *
 * The THIRD deliberate, narrow exception to "the superadmin panel never
 * opens a tenant's own database" — see the full reasoning in
 * core/tenant_admin.php::tenantPosSimpleModeStatus()/setTenantPosSimpleMode(),
 * which this endpoint is a thin wrapper around. Kept as its OWN endpoint,
 * same discipline as actions/superadmin_tenant_users.php: the one code path
 * that crosses this boundary is easy to find, audit, and remove
 * independently of the other two.
 *
 * Same guard order as every other superadmin action: host, session, POST,
 * CSRF. GET is deliberately not supported (same reasoning as
 * superadmin_tenant_users.php — a GET-cacheable endpoint that opens a
 * tenant's database on every hit defeats the point of "on demand").
 *
 * POST action=status  {tenant_id}                       -> read current state
 * POST action=set      {tenant_id, enabled, locked}      -> write it
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

$action = (string)($_POST['action'] ?? 'status');

if ($action === 'status') {
    $status = tenantPosSimpleModeStatus($tenantId);
    if ($status === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Could not read Simple Mode status for this tenant right now.']);
        exit;
    }
    echo json_encode(['success' => true] + $status);
    exit;
}

if ($action === 'set') {
    $enabled = ((string)($_POST['enabled'] ?? '0') === '1');
    $locked  = ((string)($_POST['locked'] ?? '0') === '1');
    $r = setTenantPosSimpleMode($tenantId, $enabled, $locked);
    if (!$r['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Simple Mode updated for this tenant.']);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
