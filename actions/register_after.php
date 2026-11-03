<?php
/**
 * actions/register_after.php — sends a signup's emails after the visitor has
 * their answer.
 *
 * register.php beacons this once the account is ready. actions/register_tenant.php
 * left the owner's welcome and the superadmin notice in this visitor's session
 * (registerTenant() with defer_emails), so SMTP time is never part of the wait.
 * It takes no input and only sends what this session queued, so calling it
 * again, or anonymously, sends nothing.
 */
require_once __DIR__ . '/../core/tenant_registration.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$queue = $_SESSION['bms_reg_mail'] ?? [];
unset($_SESSION['bms_reg_mail']);
session_write_close();

@ignore_user_abort(true);
@set_time_limit(120);
foreach ($queue as $d) {
    if (is_array($d)) sendDeferredRegistrationEmails($d);
}
echo json_encode(['ok' => true, 'sent' => count($queue)]);
