<?php
/**
 * actions/superadmin_record_payment.php
 *
 * Records a subscription payment for a tenant through applySubscriptionPayment()
 * (core/tenant_lifecycle_policy.php) — the same path the BJP invoice bridge uses.
 * The period starts at the later of the chosen date and the current end date;
 * a trial or expiry-suspended tenant becomes active; activate = '1' also
 * resumes a tenant an operator suspended by hand.
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

try {
    $tenant = getTenant($tenantId);
    if ($tenant === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
        exit;
    }

    $r = applySubscriptionPayment(
        $tenantId, $durationMonths, $amountTzs, 'manual', null,
        $notes !== '' ? $notes : null, (int)currentSuperadmin()['id'], $startsAt, $activate
    );
    if (!$r['ok']) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $r['error']]);
        exit;
    }

    echo json_encode([
        'success'   => true,
        'message'   => 'Payment recorded. Subscription runs ' . date('d M Y', strtotime($r['starts_at']))
                     . ' to ' . date('d M Y', strtotime($r['ends_at'])) . '.',
        'ends_at'   => $r['ends_at'],
        'activated' => $r['activated'],
    ]);

} catch (Throwable $e) {
    error_log('superadmin_record_payment: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
}
