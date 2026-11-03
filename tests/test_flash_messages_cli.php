<?php
/**
 * One-time messages instead of ?error= in the address bar.
 *   php tests/test_flash_messages_cli.php
 *
 * Static: no redirect carries error/msg text in its URL any more.
 * Unit:   bmsFlash / bmsFlashDrain / bmsFlashScript.
 * Real HTTP (php -S, logged in as admin, cookies kept between requests):
 *   a bad link redirects to a CLEAN address; the next page shows the message
 *   once; the page after that does not. Exit 0 = pass.
 */

$root = dirname(__DIR__);

// ─── Web-server router (this file run by `php -S`) ───────────────────────────
if (PHP_SAPI === 'cli-server') {
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file($root . $path) && !str_ends_with($path, '.php')) return false;
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_id'])) {
        $_SESSION['user_id'] = (int)getenv('BMS_TEST_ADMIN'); $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin';
        $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true; $_SESSION['user_lang'] = 'en';
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    chdir($root);
    require $root . '/index.php';
    return true;
}

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

echo "\n── 1. No redirect puts a message in the address ──\n";
$out = shell_exec('cd ' . escapeshellarg($root) . ' && git grep -nE "Location:[^;]*[?&](error|msg|message)=" -- "*.php" ":!tests"');
ok(trim((string)$out) === '', 'no header("Location: …?error=/msg=/message=…") left in the code' . (trim((string)$out) ? "\n      " . trim($out) : ''));
$flashUses = (int)trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && git grep -c "bmsFlashRedirect" -- "app/*.php" | wc -l'));
ok($flashUses >= 15, "$flashUses page files use bmsFlashRedirect");

echo "\n── 2. The helper ──\n";
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
chdir($root);
require_once $root . '/core/flash.php';
$_SESSION = [];
ok(bmsFlashDrain() === [] && bmsFlashScript() === '', 'nothing queued → nothing returned, no script');
bmsFlash('error', 'Invoice Not Found');
bmsFlash('weird-type', '  spaced  ');
bmsFlash('success', '   ');
$q = bmsFlashDrain();
ok(count($q) === 2 && $q[0] === ['type' => 'error', 'message' => 'Invoice Not Found'], 'queues messages in order; blank ones are dropped');
ok($q[1]['type'] === 'info' && $q[1]['message'] === 'spaced', 'unknown type becomes info; text trimmed');
ok(bmsFlashDrain() === [], 'draining clears the queue (shown once)');
for ($i = 0; $i < 20; $i++) bmsFlash('error', "m$i");
ok(count($_SESSION['bms_flash']) === 5, 'a runaway loop cannot grow the session past 5 messages');
$_SESSION = [];
bmsFlash('error', '</script><script>alert(1)</script> "quoted" \'single\'');
$js = bmsFlashScript();
ok(substr_count($js, '</script>') === 1 && !str_contains($js, '<script>alert'), 'message text is data, not markup: a </script> inside it cannot break out');
ok(str_contains($js, 'Swal.fire') && str_contains($js, 'toast:true'), 'the script shows a toast');
$code = ''; foreach (token_get_all(file_get_contents($root . '/core/flash.php')) as $t) if (is_array($t) && !in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) $code .= $t[1];
ok(!str_contains($code, 'session_start'), 'helper code never calls session_start() (the session lock is released early)');

echo "\n── 3. Real HTTP: message shows once, address stays clean ──\n";
require_once $root . '/includes/config.php';
$admin = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$port = 18000 + random_int(0, 900);
$env  = array_merge(getenv(), ['BMS_TEST_ADMIN' => (string)$admin]);
$nul  = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$srv  = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $pipes, $root, $env);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) { usleep(100000); $s = @fsockopen('127.0.0.1', $port); if ($s) { fclose($s); $up = true; } }
ok($up, "test web server started on port $port");

$cookie = '';
$http = function (string $path) use ($port, &$cookie): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 90,
        'header' => $cookie !== '' ? "Cookie: $cookie\r\n" : '']]);
    $body = (string)@file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $hdr  = $http_response_header ?? [];
    $code = preg_match('#^HTTP/\S+\s+(\d+)#', $hdr[0] ?? '', $m) ? (int)$m[1] : 0;
    $loc  = '';
    foreach ($hdr as $h) {
        if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=;]+=[^;]*)/i', $h, $c)) $cookie = $c[1];
    }
    return [$code, $loc, $body];
};

if ($up) {
    // (page that needs an id, the list it falls back to, the message it must give)
    $cases = [
        ['/invoice_view?id=999999999',   '/invoices',   'Invoice Not Found'],
        ['/product_view?id=999999999',   '/products',   'Product not found'],
        ['/sales_order_view?id=999999999', '/sales_orders', 'Order Not Found'],
        ['/quotation_view?id=999999999', '/quotations', 'Quotation Not Found'],
        ['/grn_view?id=999999999',       '/grn',        'GRN Not Found'],
    ];
    foreach ($cases as [$bad, $list, $msg]) {
        $cookie = '';
        [$code, $loc, $b1] = $http($bad);
        if ($code === 200 && $loc === '' && preg_match('#location\.replace\(("[^"]*")\)#', $b1, $mm)) { $loc = json_decode(str_replace('\/', '/', $mm[1])); $code = 302; $via = ' (browser redirect: the page had already printed its header)'; } else { $via = ''; }
        ok(in_array($code, [301, 302, 303], true) && $loc === $list, "$bad → redirects to $list$via (got $code '$loc')");
        ok($loc !== '' && !str_contains($loc, '?') && !str_contains($loc, 'error'), '   …and the address has no query string or message');
        [$c2, , $body] = $http($list);
        ok($c2 === 200 && str_contains($body, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) && str_contains($body, 'toast:true'), "   …the next page ($list) shows \"$msg\" as a toast");
        [$c3, , $body3] = $http($list);
        ok($c3 === 200 && !str_contains($body3, $msg), '   …and a refresh does not show it again');
    }
    // A forged ?error= in the address does nothing.
    $cookie = '';
    [$c, , $body] = $http('/invoices?error=' . rawurlencode('Hacked <b>by me</b>'));
    ok($c === 200 && !str_contains($body, 'Hacked'), 'a hand-typed ?error=… in the address is ignored');
}
if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }

echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
