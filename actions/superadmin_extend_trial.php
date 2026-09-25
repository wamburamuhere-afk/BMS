<?php
/**
 * actions/superadmin_extend_trial.php — extend a tenant's trial period.
 *
 * Accepts: tenant_id (int), days (7|14|30|custom int).
 * Sets trial_ends_at = MAX(NOW(), trial_ends_at) + :days INTERVAL.
 * If the tenant was suspended solely due to trial expiry, restores status to
 * 'trial' automatically.
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
$days     = (int)($_POST['days']      ?? 0);

if ($tenantId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No tenant specified.']);
    exit;
}

if ($days < 1 || $days > 365) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Days must be between 1 and 365.']);
    exit;
}

try {
    $ctrl = getControlPdo();

    $row = $ctrl->prepare("SELECT id, subdomain, status, trial_ends_at FROM tenants WHERE id = ?");
    $row->execute([$tenantId]);
    $tenant = $row->fetch(\PDO::FETCH_ASSOC);

    if (!$tenant) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Tenant not found.']);
        exit;
    }

    // Base: extend from the later of NOW() or the current trial end (avoid shrinking)
    // If no trial_ends_at yet, start from now.
    $base       = (!empty($tenant['trial_ends_at'])
                   ? "GREATEST(NOW(), trial_ends_at)"
                   : "NOW()");
    $newEndsAt  = $ctrl->query(
        "SELECT DATE_ADD({$base}, INTERVAL {$days} DAY) FROM tenants WHERE id = {$tenantId}"
    )->fetchColumn();

    // Build the UPDATE — also restore 'suspended' → 'trial' if appropriate
    $sa = currentSuperadmin();

    $ctrl->prepare(
        "UPDATE tenants
            SET trial_ends_at     = ?,
                trial_extended_by = ?,
                status            = CASE
                                        WHEN status = 'suspended' THEN 'trial'
                                        ELSE status
                                    END
          WHERE id = ?"
    )->execute([$newEndsAt, $sa['id'], $tenantId]);

    logTenantAdminAction(
        $tenantId,
        (string)$tenant['subdomain'],
        'extend_trial',
        "Extended by {$days} days — new trial_ends_at: {$newEndsAt}"
    );

    echo json_encode([
        'success'       => true,
        'message'       => "Trial extended by {$days} day" . ($days > 1 ? 's' : '') . '. New expiry: ' . date('d M Y', strtotime($newEndsAt)) . '.',
        'trial_ends_at' => $newEndsAt,
    ]);

} catch (\Throwable $e) {
    error_log('superadmin_extend_trial.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
