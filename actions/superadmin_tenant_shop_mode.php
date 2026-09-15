<?php
/**
 * actions/superadmin_tenant_shop_mode.php — view/set ONE tenant's Shop Mode
 * override from the superadmin Tenant Detail page.
 *
 * A FIFTH deliberate, narrow exception to "the superadmin panel never opens
 * a tenant's own database" — see the full reasoning in
 * core/tenant_admin.php::tenantShopModeStatus()/setTenantShopMode(), which
 * this endpoint is a thin wrapper around. Kept as its OWN endpoint, same
 * discipline as actions/superadmin_tenant_pos_simple_mode.php.
 *
 * Same guard order as every other superadmin action: host, session, POST,
 * CSRF. GET is deliberately not supported (same reasoning as
 * superadmin_tenant_pos_simple_mode.php).
 *
 * POST action=status  {tenant_id}             -> read current state
 * POST action=set      {tenant_id, enabled}   -> write it
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
    $enabled = tenantShopModeStatus($tenantId);
    if ($enabled === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Could not read Shop Mode status for this tenant right now.']);
        exit;
    }
    echo json_encode(['success' => true, 'enabled' => $enabled]);
    exit;
}

if ($action === 'set') {
    $enabled = ((string)($_POST['enabled'] ?? '0') === '1');
    $r = setTenantShopMode($tenantId, $enabled);
    if (!$r['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Shop Mode updated for this tenant.']);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
