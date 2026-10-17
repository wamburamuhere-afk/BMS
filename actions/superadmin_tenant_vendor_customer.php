<?php
/**
 * actions/superadmin_tenant_vendor_customer.php — remove the link between a tenant
 * and the BJP customer who pays for it (customer_subscription_plan.md Phase 3).
 *
 * Linking is done from BJP's own install (Customer Visits / Customer Details),
 * which can see BJP's customer list; the panel never opens BJP's database, so it
 * only offers to remove a wrong link. Guard order as every superadmin action.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/vendor_link.php';
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

// Mark a bridge alert as dealt with (core/vendor_bridge.php raises them).
if ((string)($_POST['action'] ?? '') === 'resolve_alert') {
    $alertId = (int)($_POST['alert_id'] ?? 0);
    try {
        $st = getControlPdo()->prepare("UPDATE vendor_bridge_alerts SET resolved_at = NOW(), resolved_by = ? WHERE id = ? AND resolved_at IS NULL");
        $st->execute([(string)(currentSuperadmin()['email'] ?? 'superadmin'), $alertId]);
        if ($st->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Alert not found or already resolved.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Marked as done.']);
    } catch (Throwable $e) {
        error_log('superadmin resolve alert: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    }
    exit;
}

$tenantId = (int)($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0 || (string)($_POST['action'] ?? '') !== 'unlink') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Nothing to do.']);
    exit;
}

$r = vlSetTenantCustomer($tenantId, null, 'superadmin ' . (string)(currentSuperadmin()['email'] ?? ''));
if (!$r['ok']) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $r['error']]);
    exit;
}
echo json_encode(['success' => true, 'message' => 'Link removed. Paid BJP invoices will no longer extend this tenant until it is linked again.']);
