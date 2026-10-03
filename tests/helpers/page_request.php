<?php
/**
 * tests/helpers/page_request.php — render ONE page (or API) as a given user in
 * its own PHP process and print its output. CLI-only test helper.
 *
 *   php page_request.php <file> <user_id> <is_admin 0|1> <GET-params-json> [features-json]
 *
 * <file> is relative to the project root (e.g. app/bms/product/product_view.php).
 * features-json, when given, primes $GLOBALS['__bms_features'] the way a real
 * tenant request is primed (e.g. {"pos":true,"warehouses":true,...false}).
 * Settings such as pos_simple_mode are read from the DB — the caller sets them.
 * Appends "HTTP_CODE=<n>" to STDERR.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[, $file, $uid, $isAdmin, $params] = $argv + array_fill(0, 5, '');
$features = $argv[5] ?? '';
$root = dirname(__DIR__, 2);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = '/' . $file;
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';   // links render as "/route", like a root install
require $root . '/roots.php';

$u = $pdo->prepare("SELECT user_id, username, first_name, last_name, role_id FROM users WHERE user_id = ?");
$u->execute([(int)$uid]);
$user = $u->fetch(PDO::FETCH_ASSOC) ?: ['username' => 'test', 'first_name' => 'Test', 'last_name' => 'User', 'role_id' => 0];
$_SESSION = [
    'user_id'    => (int)$uid,
    'username'   => $user['username'],
    'first_name' => $user['first_name'],
    'last_name'  => $user['last_name'],
    'role_id'    => (int)$user['role_id'],
    'user_role'  => $isAdmin === '1' ? 'Admin' : 'Staff',
    'is_admin'   => $isAdmin === '1',
    'csrf_token' => 'test-csrf',
];
if ($features !== '') $GLOBALS['__bms_features'] = json_decode($features, true);
$_GET = json_decode($params, true) ?: [];

// "--routes": print {route: bmsRouteAvailable(route)} for every mapped route,
// evaluated in this fresh process (settings read now, not cached by the caller).
if ($file === '--routes') {
    $out = [];
    foreach (array_keys($routes) as $r) $out[$r] = bmsRouteAvailable($r);
    echo json_encode($out);
    exit;
}

register_shutdown_function(function () { fwrite(STDERR, "\nHTTP_CODE=" . (http_response_code() ?: 200)); });
chdir($root);   // the router runs pages from the root: they include 'header.php' relatively
include $root . '/' . $file;
