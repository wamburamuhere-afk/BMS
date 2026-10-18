<?php
/**
 * api/platform/vendor_link_kick.php — fire-and-forget nudge after a signup.
 *
 * register.php beacons this when an account is ready, so the new company
 * becomes a BJP customer within seconds instead of waiting for the next cron
 * run or staff page load (service_plans_plan.md Phase 4). It takes no input
 * and only processes what registration already queued (vendor_link_queue), so
 * calling it anonymously or repeatedly is harmless. Platform root only.
 */
// scope-audit: skip — customers are company-wide (no project scope); no input is read
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/vendor_context.php';
require_once __DIR__ . '/../../core/vendor_link.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
if (!isPlatformVendorContext()) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

@ignore_user_abort(true);
@set_time_limit(60);
global $pdo;
$r = vlDrainLinkQueue($pdo, 5);
echo json_encode(['ok' => true, 'processed' => $r['processed']]);
