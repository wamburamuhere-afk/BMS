<?php
/**
 * actions/superadmin_tenant_usage.php — on-demand snapshot of one tenant's
 * CURRENT active-user count and storage usage.
 *
 * The one deliberate, narrow exception to "the superadmin panel never opens a
 * tenant's own database" — see the full reasoning in
 * core/tenant_quotas.php::tenantUsageSnapshotFor(), which this endpoint is a
 * thin wrapper around. Kept as its OWN endpoint, separate from
 * superadmin_tenant_quotas.php (which only ever touches the control database),
 * so the one code path that crosses this boundary is easy to find, audit, and
 * — if it is ever misused — remove, without touching the quota-writing path
 * at all.
 *
 * Same guard order as every other superadmin action: host, session, POST,
 * CSRF. GET is deliberately not supported even though this only reads — a
 * GET-cacheable endpoint that opens a tenant's own database on every hit from
 * a crawler or a browser prefetch is exactly the kind of thing that turns "on
 * demand" into "constantly," which defeats the whole point of it being
 * on-demand.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/tenant_quotas.php';
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

$snapshot = tenantUsageSnapshotFor($tenantId);

if ($snapshot === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Could not read current usage for this tenant right now.']);
    exit;
}

echo json_encode([
    'success'            => true,
    'active_users'       => $snapshot['active_users'],
    'storage_used_bytes' => $snapshot['storage_used_bytes'],
    'storage_used_mb'    => round($snapshot['storage_used_bytes'] / (1024 * 1024), 2),
]);
