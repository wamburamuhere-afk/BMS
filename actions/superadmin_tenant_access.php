<?php
/**
 * actions/superadmin_tenant_access.php — give a tenant more time, or set the
 * grace period that applies to them alone.
 *
 * Replaces actions/superadmin_extend_trial.php, which only ever moved
 * `trial_ends_at`: for an ACTIVE paying customer asking for a few more days it
 * changed nothing they would notice. extendTenantAccess() moves whichever date
 * actually governs the tenant in front of you — see
 * core/tenant_lifecycle_policy.php.
 *
 * Same guard order as every other superadmin action: host, session, POST, CSRF.
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

$action   = (string)($_POST['action'] ?? 'extend');
$tenantId = (int)($_POST['tenant_id'] ?? 0);

if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

if ($action === 'set_grace_days') {
    // Blank means "follow the platform default", which is NOT the same as 0
    // ("cut them off the moment it expires"), so an empty string maps to NULL
    // rather than being cast to an integer.
    $raw  = trim((string)($_POST['grace_days'] ?? ''));
    $days = $raw === '' ? null : (int)$raw;

    $r = setTenantGraceDays($tenantId, $days);
    if (!$r['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => $days === null
        ? 'This tenant now follows the platform default grace period ('
          . tenantDefaultGraceDays() . ' days).'
        : 'Grace period for this tenant set to ' . $days . ' day' . ($days === 1 ? '' : 's') . '.']);
    exit;
}

if ($action !== 'extend') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

$days = (int)($_POST['days'] ?? 0);
$r    = extendTenantAccess($tenantId, $days);

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

$what = $r['field'] === 'trial_ends_at' ? 'Trial' : 'Subscription';
$msg  = $what . ' extended by ' . $days . ' day' . ($days === 1 ? '' : 's')
      . ' — now runs to ' . date('d M Y', strtotime((string)$r['ends_at'])) . '.'
      . ($r['resumed'] ? ' Service has been restored.' : '');

echo json_encode([
    'success'  => true,
    'message'  => $msg,
    'ends_at'  => $r['ends_at'],
    'field'    => $r['field'],
    'resumed'  => $r['resumed'],
]);
