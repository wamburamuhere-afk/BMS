<?php
/**
 * actions/superadmin_tenant_action.php — suspend / activate / delete one tenant.
 *
 * Every guard runs before any work: correct host, signed-in operator, CSRF, POST
 * only. requireSuperadminApi() below is the JSON sibling of requireSuperadmin() —
 * an unauthenticated AJAX call must get a 401 it can act on, not a redirect to a
 * login page it would render into a dialog.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// A tenant subdomain gets a flat 404 — the panel does not exist as far as
// tenants are concerned.
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

$action   = (string)($_POST['action'] ?? '');
$tenantId = (int)($_POST['tenant_id'] ?? 0);

if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

switch ($action) {
    case 'suspend':
        $r = suspendTenant($tenantId, trim((string)($_POST['reason'] ?? '')));
        $okMsg = 'Tenant suspended. Only this tenant is affected.';
        break;

    case 'activate':
        $r = activateTenant($tenantId);
        $okMsg = 'Tenant reactivated.';
        break;

    case 'delete':
        // The typed company name is verified inside deleteTenant() against the
        // stored value, not here — so the check cannot be bypassed by calling
        // the function from anywhere else.
        $r = deleteTenant($tenantId, (string)($_POST['confirm_name'] ?? ''));
        $okMsg = 'Tenant deleted. Its database and database user have been removed.';
        break;

    default:
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
}

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

echo json_encode(['success' => true, 'message' => $okMsg]);
