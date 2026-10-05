<?php
/**
 * actions/superadmin_extend_trial.php — DEPRECATED shim.
 *
 * This endpoint used to hold the extension logic, and it only half worked: it
 * moved `trial_ends_at` and restored a suspended tenant to 'trial', so for an
 * ACTIVE paying customer who asked for a few more days it changed nothing they
 * would ever notice.
 *
 * The real thing is now extendTenantAccess() in
 * core/tenant_lifecycle_policy.php, behind actions/superadmin_tenant_access.php,
 * and it moves whichever date actually governs the tenant in front of you.
 *
 * Kept as a thin forward rather than deleted: the URL may be in a bookmark, an
 * old tab, or a script, and a 404 there would look like the panel is broken.
 * It takes the same parameters and answers in the same shape as before, so an
 * old caller cannot tell the difference — except that it now works for
 * subscriptions too.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/tenant_lifecycle_policy.php';
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
$days     = (int)($_POST['days']      ?? 0);

if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

$r = extendTenantAccess($tenantId, $days);

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

$what = $r['field'] === 'trial_ends_at' ? 'Trial' : 'Subscription';

echo json_encode([
    'success'       => true,
    'message'       => $what . ' extended by ' . $days . ' day' . ($days === 1 ? '' : 's')
                     . '. New expiry: ' . date('d M Y', strtotime((string)$r['ends_at'])) . '.'
                     . ($r['resumed'] ? ' Service has been restored.' : ''),
    // The old response key, kept for any caller that reads it. It now carries
    // whichever date was actually moved.
    'trial_ends_at' => $r['ends_at'],
    'ends_at'       => $r['ends_at'],
    'field'         => $r['field'],
]);
