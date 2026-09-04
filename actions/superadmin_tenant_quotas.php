<?php
/**
 * actions/superadmin_tenant_quotas.php — set one tenant's seat/storage limits.
 *
 * Mirrors actions/superadmin_tenant_features.php's guard order exactly:
 * correct host, signed-in operator, POST only, CSRF. A tenant subdomain gets a
 * flat 404 — the panel does not exist as far as tenants are concerned.
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

// Blank field = unlimited. A non-numeric or negative value is treated as
// blank rather than rejected outright — the input is a plain text/number
// field, not a strict form, and "unlimited" is always a safe fallback.
$rawUsers   = trim((string)($_POST['max_users'] ?? ''));
$rawStorage = trim((string)($_POST['max_storage_mb'] ?? ''));
$maxUsers   = ($rawUsers !== '' && ctype_digit($rawUsers))   ? (int)$rawUsers   : null;
$maxStorage = ($rawStorage !== '' && ctype_digit($rawStorage)) ? (int)$rawStorage : null;

$r = setTenantQuotas($tenantId, $maxUsers, $maxStorage);

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => $r['changed']
        ? 'Limits updated. It takes effect on this tenant\'s next request; no other tenant is affected.'
        : 'No changes — this tenant already had exactly those limits.',
]);
