<?php
/**
 * actions/superadmin_apply_plan.php — apply one plan's features + quotas to
 * one tenant, right now. A thin wrapper around applyPlanToTenant() — see its
 * docblock in core/plans.php for why this introduces no new enforcement.
 *
 * Guard order matches every other superadmin action: host, session, POST, CSRF.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/plans.php';
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
$planId   = (int)($_POST['plan_id'] ?? 0);
if ($tenantId <= 0 || $planId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A tenant and a plan are both required.']);
    exit;
}

$r = applyPlanToTenant($tenantId, $planId);

if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Plan applied — ' . $r['features_changed'] . ' module change(s), quotas '
               . ($r['quotas_changed'] ? 'updated' : 'unchanged') . '.',
]);
