<?php
/**
 * actions/superadmin_tenant_supplier_access.php — view/set ONE tenant's
 * "Supplier Access" override from the superadmin Tenant Detail page.
 *
 * Thin wrapper around core/tenant_admin.php::tenantSupplierAccessStatus()/
 * setTenantSupplierAccess() — direct copy of
 * actions/superadmin_tenant_advanced_supplier.php's own structure, its own
 * dedicated endpoint for the same auditability reasons.
 *
 * Same guard order as every other superadmin action: host, session, POST,
 * CSRF. GET is deliberately not supported.
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
    $status = tenantSupplierAccessStatus($tenantId);
    if ($status === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Could not read Supplier Access status for this tenant right now.']);
        exit;
    }
    echo json_encode(['success' => true] + $status);
    exit;
}

if ($action === 'set') {
    $enabled = ((string)($_POST['enabled'] ?? '0') === '1');
    $locked  = ((string)($_POST['locked'] ?? '0') === '1');
    $r = setTenantSupplierAccess($tenantId, $enabled, $locked);
    if (!$r['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'Supplier Access updated for this tenant.']);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
