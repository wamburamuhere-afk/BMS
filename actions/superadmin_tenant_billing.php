<?php
/**
 * actions/superadmin_tenant_billing.php — save billing details for one tenant.
 *
 * Updates billing_cycle, billing_amount_tzs, next_billing_date, payment_status.
 * Logs to tenant_admin_log (action: 'billing_update').
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

$billingCycle  = in_array($_POST['billing_cycle'] ?? '', ['monthly','annual'], true) ? $_POST['billing_cycle'] : null;
$billingAmount = $_POST['billing_amount_tzs'] !== '' ? (int)($_POST['billing_amount_tzs'] ?? 0) : null;
$nextBillingDate = trim((string)($_POST['next_billing_date'] ?? ''));
if ($nextBillingDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextBillingDate)) {
    $nextBillingDate = null;
}
$paymentStatus = in_array($_POST['payment_status'] ?? '', ['none','current','pending','overdue'], true)
                 ? $_POST['payment_status'] : 'none';

try {
    $ctrl = getControlPdo();

    $row = $ctrl->prepare("SELECT id, subdomain FROM tenants WHERE id = ?");
    $row->execute([$tenantId]);
    $tenant = $row->fetch(\PDO::FETCH_ASSOC);

    if (!$tenant) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
        exit;
    }

    $ctrl->prepare(
        "UPDATE tenants
            SET billing_cycle       = ?,
                billing_amount_tzs  = ?,
                next_billing_date   = ?,
                payment_status      = ?
          WHERE id = ?"
    )->execute([
        $billingCycle,
        $billingAmount,
        $nextBillingDate !== '' ? $nextBillingDate : null,
        $paymentStatus,
        $tenantId,
    ]);

    logTenantAdminAction(
        $tenantId,
        (string)$tenant['subdomain'],
        'billing_update',
        "Billing: {$billingCycle}, " . ($billingAmount ? number_format((int)$billingAmount) . ' TZS, ' : '') . "status: {$paymentStatus}"
    );

    echo json_encode(['success' => true, 'message' => 'Billing details saved.']);

} catch (\Throwable $e) {
    error_log('superadmin_tenant_billing.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
