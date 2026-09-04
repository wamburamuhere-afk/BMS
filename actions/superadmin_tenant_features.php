<?php
/**
 * actions/superadmin_tenant_features.php — grant or revoke feature areas for ONE
 * tenant.
 *
 * Same guard order as actions/superadmin_tenant_action.php, which this
 * deliberately mirrors rather than reinvents: correct host, signed-in operator,
 * POST only, CSRF. A tenant subdomain gets a flat 404 — the panel does not exist
 * as far as tenants are concerned, and that is exactly the property that makes a
 * control-database flag something a tenant cannot reach.
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

csrf_check();   // §21 — exits 419 on mismatch

$tenantId = (int)($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

// The panel posts features[<key>] = 0|1 for every switch it rendered, so an
// unchecked box arrives as an explicit 0 rather than simply being absent.
// Keys the catalogue does not know are ignored inside setTenantFeatures() —
// a request cannot invent a feature by naming one.
$raw = $_POST['features'] ?? [];
if (!is_array($raw)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Malformed request.']);
    exit;
}

$desired = [];
foreach ($raw as $key => $val) {
    if (!is_string($key)) continue;
    $desired[$key] = ((string)$val === '1' || $val === true || $val === 1);
}

if (!$desired) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No modules were submitted.']);
    exit;
}

$r = setTenantFeatures($tenantId, $desired);

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => $r['changed'] === 0
        ? 'No changes — this tenant already had exactly those modules.'
        : $r['changed'] . ' module' . ($r['changed'] === 1 ? '' : 's')
          . ' updated. It takes effect on this tenant\'s next request; no other tenant is affected.',
    'changed' => $r['changed'],
]);
