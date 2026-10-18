<?php
/**
 * api/cron/vendor_bridge_retry.php
 * -----------------------------------------------------------------------
 * Retries paid BJP subscription invoices that could not extend their company
 * yet (control database briefly unreachable, or not upgraded yet). Normally
 * the payment applies at once and this finds nothing to do. Also turns newly
 * registered companies into BJP customers (vendor_link_queue).
 *
 * Must be called on the platform root (BJP's own install, e.g.
 * https://bms.bjptechnologies.co.tz/api/cron/vendor_bridge_retry.php) — the
 * bridge does nothing anywhere else. Safe to run as often as wanted.
 *
 * Authentication: Bearer token matching CRON_SECRET env variable.
 */

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/vendor_bridge.php';

header('Content-Type: application/json');

$expectedSecret = trim((string)getenv('CRON_SECRET'));
if ($expectedSecret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'CRON_SECRET not configured']);
    exit;
}
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authHeader === '' && function_exists('getallheaders')) {
    $hdrs       = getallheaders();
    $authHeader = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
}
if (!hash_equals('Bearer ' . $expectedSecret, trim($authHeader))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!isPlatformVendorContext()) {
    echo json_encode(['ok' => true, 'skipped' => 'not the platform root — nothing to do here']);
    exit;
}

ignore_user_abort(true);
set_time_limit(120);
global $pdo;
// Newly registered companies first, so a paid invoice can find its customer.
$links = vlDrainLinkQueue($pdo);
// Paid downgrades whose date has come, for companies that have not visited since.
require_once __DIR__ . '/../../core/plans.php';
$planChanges = planApplyDueScheduled(null);
echo json_encode(['ok' => true] + vendorBridgeRetry($pdo) + ['customer_links' => $links, 'scheduled_plan_changes' => $planChanges]);
