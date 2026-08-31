<?php
/**
 * actions/superadmin_login.php — POST handler for the platform operator sign-in.
 *
 * Authenticates ONLY against bms_control.superadmins. It never opens a tenant
 * database and never inspects a tenant's users table.
 */
require_once __DIR__ . '/../core/superadmin_auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// A tenant subdomain must not even be able to probe this endpoint.
assertSuperadminHost();
superadminSessionReady();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

csrf_check();   // §21 — exits 419 on mismatch

$result = attemptSuperadminLogin($_POST['email'] ?? '', $_POST['password'] ?? '');

if (!$result['ok']) {
    // 401 rather than 200 so the failure is visible to logs and monitoring.
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $result['error']]);
    exit;
}

echo json_encode(['success' => true, 'redirect' => '/app/superadmin/index.php']);
