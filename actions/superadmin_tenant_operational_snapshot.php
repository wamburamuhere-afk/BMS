<?php
/**
 * actions/superadmin_tenant_operational_snapshot.php — one tenant's shop
 * count + rough record-volume signal, for the superadmin Tenant Detail
 * page's Usage & Analytics tab.
 *
 * The SIXTH deliberate, narrow exception to "the superadmin panel never
 * opens a tenant's own database" — see the full reasoning in
 * core/tenant_admin.php::tenantOperationalSnapshot(), which this endpoint is
 * a thin wrapper around. Kept as its OWN endpoint, same discipline as every
 * other action in this narrow-exception family.
 *
 * Same guard order as every other superadmin action: host, session, POST,
 * CSRF. GET is deliberately not supported (same reasoning as the sibling
 * endpoints — a GET-cacheable endpoint that opens a tenant's database on
 * every hit defeats the point of "on demand").
 *
 * POST {tenant_id} -> read the snapshot
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

$snapshot = tenantOperationalSnapshot($tenantId);
if ($snapshot === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Could not read this tenant\'s shop/record counts right now.']);
    exit;
}

echo json_encode(['success' => true] + $snapshot);
