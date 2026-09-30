<?php
/**
 * Test harness (not a suite): runs one app page / API as a given user under php-cgi,
 * so real response headers (Location, status) are emitted. Driven by mmFixtureRun().
 * Input: env MMSCOPE_PAYLOAD = base64(json{path,user,mm_only,get,post}).
 */
// Local test runner only: php-cgi launched by mmFixtureRun(). A real web request always has a Host header.
if (PHP_SAPI !== 'cgi-fcgi' || isset($_SERVER['HTTP_HOST']) || getenv('MMSCOPE_PAYLOAD') === false) {
    http_response_code(404);
    exit;
}

$p = json_decode(base64_decode((string)getenv('MMSCOPE_PAYLOAD')), true);
if (!is_array($p) || empty($p['path'])) { echo 'bad payload'; exit(2); }

$root = dirname(__DIR__);
$file = realpath($root . '/' . ltrim($p['path'], '/'));
if (!$file || strpos($file, realpath($root)) !== 0) { echo 'bad path'; exit(2); }

$isPost = !empty($p['post']);
$_SERVER['REQUEST_METHOD'] = $isPost ? 'POST' : 'GET';
$_SERVER['REQUEST_URI']    = '/' . ltrim($p['path'], '/');
$_SERVER['PHP_SELF']       = $_SERVER['REQUEST_URI'];
$_SERVER['SCRIPT_NAME']    = $_SERVER['REQUEST_URI'];
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'mm-scope-harness';
$_GET  = $p['get'] ?? [];
$_POST = $p['post'] ?? [];
$_REQUEST = array_merge($_GET, $_POST);

require_once $root . '/roots.php';
require_once __DIR__ . '/mm_scope_fixture.inc.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$_SESSION = [];
global $pdo;
mmFixtureAs($pdo, (int)$p['user']);
mmFixtureTenant((bool)$p['mm_only']);
$_SESSION['user_lang'] = 'en';   // assertions match English labels regardless of the user's saved language
foreach (($p['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
if ($isPost) {
    $_POST['_csrf'] = csrf_token();
    $_REQUEST['_csrf'] = $_POST['_csrf'];
}

register_shutdown_function(function () {
    $loc = null;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
    }
    echo "\n@@MMSCOPE@@" . json_encode(['notice' => $_SESSION['mm_scope_notice'] ?? null, 'location' => $loc]);
});

chdir(dirname($file));
require $file;
