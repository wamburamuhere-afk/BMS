<?php
/**
 * actions/superadmin_record_payment.php
 *
 * Records a subscription payment for a tenant:
 *   - Inserts a row into tenant_payments (the payment ledger).
 *   - Updates tenants.subscription_ends_at, billing_cycle, billing_amount_tzs,
 *     next_billing_date, payment_status = 'current'.
 *   - Optionally activates a suspended tenant (param activate = '1').
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
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

csrf_check();

$tenantId       = (int)($_POST['tenant_id'] ?? 0);
$amountTzs      = (int)($_POST['amount_tzs'] ?? 0);
$durationMonths = (int)($_POST['duration_months'] ?? 0);
$startsAt       = trim((string)($_POST['starts_at'] ?? date('Y-m-d')));
$notes          = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500);
$activate       = (($_POST['activate'] ?? '0') === '1');

// --- Validate inputs -------------------------------------------------------
if ($tenantId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid tenant.']);
    exit;
}
if ($amountTzs < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Amount must be at least 1 TZS.']);
    exit;
}
if (!in_array($durationMonths, [1, 3, 6, 12], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Duration must be 1, 3, 6 or 12 months.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsAt)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid start date.']);
    exit;
}

$cycleMap = [1 => 'monthly', 3 => 'quarterly', 6 => 'biannual', 12 => 'annual'];
$billingCycle = $cycleMap[$durationMonths];

try {
    $ctrl   = getControlPdo();
    $tenant = getTenant($tenantId);
    if ($tenant === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
        exit;
    }

    // Calculate subscription end date
    $endsAt = date('Y-m-d', strtotime("{$startsAt} +{$durationMonths} months"));

    $ctrl->beginTransaction();

    // 1. Insert payment record
    $ctrl->prepare("
        INSERT INTO tenant_payments
            (tenant_id, amount_tzs, duration_months, starts_at, ends_at, notes, recorded_by, recorded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $tenantId, $amountTzs, $durationMonths, $startsAt, $endsAt,
        $notes !== '' ? $notes : null,
        currentSuperadmin()['id'],
    ]);

    // 2. Update tenant billing fields
    $newStatus = null;
    if ($activate && $tenant['status'] === 'suspended') {
        $newStatus = 'active';
    }

    $updateSql = "
        UPDATE tenants
           SET subscription_ends_at = ?,
               billing_cycle        = ?,
               billing_amount_tzs   = ?,
               next_billing_date    = ?,
               payment_status       = 'current'
               " . ($newStatus !== null ? ", status = 'active', activated_at = NOW()" : "") . "
         WHERE id = ?
    ";
    $ctrl->prepare($updateSql)->execute([$endsAt, $billingCycle, $amountTzs, $endsAt, $tenantId]);

    // 3. Audit log
    $detail = number_format($amountTzs) . ' TZS for ' . $durationMonths . ' month(s); '
            . 'runs ' . $startsAt . ' → ' . $endsAt
            . ($newStatus !== null ? '; tenant re-activated' : '');
    logTenantAdminAction($tenantId, (string)($tenant['subdomain'] ?? ''), 'payment_recorded', $detail);

    $ctrl->commit();

    echo json_encode([
        'success'  => true,
        'message'  => 'Payment recorded. Subscription runs until ' . date('d M Y', strtotime($endsAt)) . '.',
        'ends_at'  => $endsAt,
        'activated' => $newStatus === 'active',
    ]);

} catch (Throwable $e) {
    if (isset($ctrl) && $ctrl->inTransaction()) {
        try { $ctrl->rollBack(); } catch (Throwable $_e) {}
    }
    error_log('superadmin_record_payment: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
}
