<?php
/**
 * actions/superadmin_tenant_mm_simple_mode.php — view/set ONE tenant's MM
 * Simple Mode from the superadmin Tenant Detail page.
 *
 * Simple Mode ON  (default) — transactions are saved without GL posting.
 *   journal_entry_id stays NULL; all operational metrics (dashboard, shifts,
 *   reports) still work because status='posted' is set directly.
 * Simple Mode OFF (advanced) — full double-entry via core/mm_posting.php.
 *   Requires mm_networks.float_account_id and the mm_gl_cash_float system
 *   setting (set by migrations/tenant/2026_09_26_mm_gl_accounts.php).
 *
 * POST action=status  {tenant_id}           -> read current state
 * POST action=set     {tenant_id, enabled}  -> write it
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

$action = (string)($_POST['action'] ?? 'status');

if ($action === 'status') {
    $status = tenantMmSimpleModeStatus($tenantId);
    if ($status === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Could not read MM Simple Mode status for this tenant right now.']);
        exit;
    }
    echo json_encode(['success' => true] + $status);
    exit;
}

if ($action === 'set') {
    $enabled = ((string)($_POST['enabled'] ?? '1') === '1');
    $r = setTenantMmSimpleMode($tenantId, $enabled);
    if (!$r['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }
    echo json_encode(['success' => true, 'message' => 'MM Simple Mode updated for this tenant.']);
    exit;
}

http_response_code(422);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
