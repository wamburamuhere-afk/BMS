<?php
/**
 * actions/superadmin_schedule_plan.php — queue or cancel a future plan change for one tenant.
 *
 * action=schedule: sets scheduled_plan_key + scheduled_plan_at on the tenant row.
 * action=clear:    clears the scheduled change (planClearSchedule).
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

$action   = trim($_POST['action'] ?? '');
$tenantId = (int)($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A tenant is required.']);
    exit;
}

if ($action === 'clear') {
    planClearSchedule($tenantId);
    tenantLifecycleLog($tenantId, '', 'plan_schedule_cleared', 'Scheduled plan change cancelled by superadmin', 'system');
    echo json_encode(['success' => true, 'message' => 'Scheduled change removed.']);
    exit;
}

if ($action === 'schedule') {
    $planId = (int)($_POST['plan_id'] ?? 0);
    $date   = trim($_POST['date'] ?? '');
    if ($planId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A plan is required.']);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid date (YYYY-MM-DD) is required.']);
        exit;
    }

    $ctrl = getControlPdo();
    $pRow = $ctrl->prepare("SELECT plan_key, name FROM plans WHERE id = ? LIMIT 1");
    $pRow->execute([$planId]);
    $plan = $pRow->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Plan not found.']);
        exit;
    }

    if (!controlColumnExists('tenants', 'scheduled_plan_key')) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Scheduled plan columns are not set up on this server yet.']);
        exit;
    }

    $tRow = $ctrl->prepare("SELECT subdomain, plan FROM tenants WHERE id = ? LIMIT 1");
    $tRow->execute([$tenantId]);
    $tenant = $tRow->fetch(PDO::FETCH_ASSOC);
    if (!$tenant) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
        exit;
    }

    $ctrl->prepare("UPDATE tenants SET scheduled_plan_key = ?, scheduled_plan_at = ? WHERE id = ?")
         ->execute([$plan['plan_key'], $date, $tenantId]);

    tenantLifecycleLog($tenantId, (string)$tenant['subdomain'], 'plan_change_scheduled',
        ($tenant['plan'] ?? '?') . ' → ' . $plan['plan_key'] . ' on ' . $date . ' (set by superadmin)', 'system');

    echo json_encode([
        'success' => true,
        'message' => '"' . $plan['name'] . '" scheduled for ' . date('d M Y', strtotime($date)) . '.',
    ]);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
