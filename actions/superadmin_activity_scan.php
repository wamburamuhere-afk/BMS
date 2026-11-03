<?php
/**
 * actions/superadmin_activity_scan.php — company activity alerts while the
 * superadmin panel is open (core/tenant_activity_alerts.php).
 *
 * Every superadmin page beacons this; the scan itself runs at most once per
 * 10 minutes (platform setting activity_alerts_last_scan), so opening pages
 * never waits on it. The daily cron (api/cron/trial_enforcement.php) runs the
 * same scan when nobody has the panel open.
 */
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/tenant_activity_alerts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}
if (currentSuperadmin() === null) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}
csrf_check();
session_write_close();   // never hold the superadmin's session while scanning

$last = (int)getPlatformSetting('activity_alerts_last_scan', '0');
if (time() - $last < 600) {
    echo json_encode(['success' => true, 'skipped' => true]);
    exit;
}
setPlatformSetting('activity_alerts_last_scan', (string)time());

@ignore_user_abort(true);
@set_time_limit(120);
$r = runTenantActivityAlerts();
echo json_encode(['success' => true, 'sent' => count($r['sent'])]);
