<?php
/**
 * actions/register_status.php — "did my signup finish?" for register.php.
 *
 * Asked only when the browser lost actions/register_tenant.php's answer (a
 * timeout or dropped connection) while the server carried on provisioning.
 * Reads nothing but this visitor's own session record of that request, keyed
 * by the random reg_token the page sent with it, so it reveals nothing about
 * any other signup.
 *
 * GET ?token=… → { state: running|done|failed|unknown, response?, message? }
 */
require_once __DIR__ . '/../core/tenant_registration.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$rec = null;
$token = (string)($_GET['token'] ?? '');
if (preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
    $rec = $_SESSION['bms_reg'][$token] ?? null;
}
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$r = resolveTenantFromRequest();
if (in_array($r['status'] ?? '', ['found', 'unknown'], true)) {
    http_response_code(404);
    echo json_encode(['state' => 'unknown']);
    exit;
}

if (!$rec) {
    echo json_encode(['state' => 'unknown']);
} elseif ($rec['state'] === 'done') {
    echo json_encode(['state' => 'done', 'response' => $rec['response']]);
} elseif ($rec['state'] === 'failed') {
    echo json_encode(['state' => 'failed', 'message' => $rec['message'] ?? '']);
} else {
    echo json_encode(['state' => 'running']);
}
