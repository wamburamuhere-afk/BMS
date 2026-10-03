<?php
/**
 * tests/helpers/field_reports_request.php — run ONE Field Reports endpoint as a
 * given user, in its own PHP process, and print its output.
 * Used by tests/test_field_reports_cli.php only. CLI-only.
 *
 *   php field_reports_request.php <endpoint> <user_id> <is_admin 0|1> <perms-json> <GET|POST> <params-json> [features-json]
 *
 * The session is forged (no login), so each call is exactly "this user, this
 * request". The HTTP status is appended to STDERR as "HTTP_CODE=<n>".
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[, $endpoint, $uid, $isAdmin, $perms, $method, $params] = $argv + array_fill(0, 8, '');
$features = $argv[7] ?? '';
$root = dirname(__DIR__, 2);

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';   // buildUrl() reads it
require $root . '/roots.php';
require_once $root . '/core/field_reports.php';

$u = $pdo->prepare("SELECT user_id, username, first_name, last_name, role_id FROM users WHERE user_id = ?");
$u->execute([(int)$uid]);
$user = $u->fetch(PDO::FETCH_ASSOC) ?: ['username' => 'test', 'first_name' => 'Test', 'last_name' => 'User', 'role_id' => 0];

$_SESSION = [
    'user_id'     => (int)$uid,
    'username'    => $user['username'],
    'first_name'  => $user['first_name'],
    'last_name'   => $user['last_name'],
    'role_id'     => (int)$user['role_id'],
    'user_role'   => $isAdmin === '1' ? 'Admin' : 'Staff',
    'is_admin'    => $isAdmin === '1',
    'permissions' => ['field_visits' => json_decode($perms, true) ?: []],
    'csrf_token'  => 'test-csrf',
];
if ($features !== '') $GLOBALS['__bms_features'] = json_decode($features, true);

$p = json_decode($params, true) ?: [];
if ($method === 'POST') { $_POST = $p + ['_csrf' => 'test-csrf']; $_GET = []; }
else                    { $_GET = $p; $_POST = []; }

register_shutdown_function(function () { fwrite(STDERR, "\nHTTP_CODE=" . (http_response_code() ?: 200)); });
include $root . '/' . $endpoint;
