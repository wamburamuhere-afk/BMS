<?php
/**
 * tests/helpers/product_import_request.php — run api/import_products.php ONCE as a
 * given user, in its own PHP process, with a CSV "uploaded". CLI-only; used by
 * tests/test_product_import_cli.php.
 *
 *   php product_import_request.php <user_id> <is_admin 0|1> <params-json> [csv-path] [can_create 0|1]
 *
 * The session is forged (no login). The HTTP status is appended to STDERR as
 * "HTTP_CODE=<n>".
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

[, $uid, $isAdmin, $params, $csv, $canCreate] = $argv + array_fill(0, 6, '');
$root = dirname(__DIR__, 2);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require $root . '/roots.php';

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
    'permissions' => ['products' => ['view' => true, 'create' => $canCreate !== '0', 'edit' => false, 'delete' => false]],
    'csrf_token'  => 'test-csrf',
];
$_POST = (json_decode($params, true) ?: []) + ['_csrf' => 'test-csrf'];
$_GET = [];
if ($csv !== '' && is_file($csv)) {
    $_FILES['file'] = ['name' => basename($csv), 'type' => 'text/csv', 'tmp_name' => $csv, 'error' => UPLOAD_ERR_OK, 'size' => filesize($csv)];
}
register_shutdown_function(function () { fwrite(STDERR, "\nHTTP_CODE=" . (http_response_code() ?: 200)); });
include $root . '/api/import_products.php';
