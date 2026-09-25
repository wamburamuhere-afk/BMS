<?php
/**
 * actions/superadmin_bulk_action.php — apply one action to multiple tenants.
 *
 * Supported actions: suspend, activate.
 * Each tenant is processed individually; the response reports per-tenant
 * success/fail counts. The audit trail is identical to N single-tenant
 * actions (one log row per tenant).
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

$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['suspend', 'activate'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unknown bulk action.']);
    exit;
}

$rawIds = $_POST['tenant_ids'] ?? [];
if (!is_array($rawIds) || empty($rawIds)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenants selected.']);
    exit;
}

$tenantIds = array_unique(array_filter(array_map('intval', $rawIds)));
if (count($tenantIds) > 100) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Maximum 100 tenants per bulk action.']);
    exit;
}

$okCount   = 0;
$failCount = 0;
$errors    = [];

foreach ($tenantIds as $tid) {
    try {
        if ($action === 'suspend') {
            $r = suspendTenant($tid, 'Bulk suspend');
        } else {
            $r = activateTenant($tid);
        }
        if ($r['ok']) {
            $okCount++;
        } else {
            $failCount++;
            $errors[] = "Tenant $tid: " . ($r['error'] ?? 'unknown error');
        }
    } catch (Throwable $e) {
        $failCount++;
        $errors[] = "Tenant $tid: " . $e->getMessage();
        error_log("superadmin_bulk_action: tenant $tid $action error: " . $e->getMessage());
    }
}

echo json_encode([
    'success'    => true,
    'ok_count'   => $okCount,
    'fail_count' => $failCount,
    'errors'     => array_slice($errors, 0, 10), // cap at 10 for JSON size
    'message'    => "{$okCount} tenant" . ($okCount !== 1 ? 's' : '') . " {$action}d successfully."
                  . ($failCount ? " {$failCount} failed." : ''),
]);
