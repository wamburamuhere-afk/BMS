<?php
/**
 * Record pages with the id in the path: /invoice_view/231 (core/detail_routes.php).
 *   php tests/test_detail_urls_cli.php
 *
 * Unit:   which addresses become path-style, which never do.
 * Real HTTP (php -S, logged in as admin): for every listed page that has a
 *   record in this database — ?id=N redirects to /page/N, /page/N opens a full
 *   page with NO relative link or call (a relative "invoices.php" would resolve
 *   to /invoice_view/invoices.php and break), other query values survive, POST
 *   to the path form is served, an unknown id falls back cleanly.
 * Exit 0 = pass.
 */

$root = dirname(__DIR__);

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

// CI has no database and no includes/config.php, and roots.php needs both. There
// only the static checks run; the rest runs wherever the app is configured.
if (!is_file($root . '/includes/config.php')) {
    echo "
── Static checks (no includes/config.php: router and HTTP checks skipped) ──
";
    $al = require $root . '/core/route_aliases.php';
    $dl = require $root . '/core/detail_routes.php';
    $badA = array_filter(array_keys($al) + [], fn($k) => preg_match('#^(api|ajax|actions)/#', $k));
    ok(is_array($al) && count($al) > 100 && !$badA, 'route_aliases.php loads, ' . count($al) . ' old addresses, none under api/ajax/actions');
    ok(!array_filter($al, fn($v) => str_contains($v, '/') || isset($al[$v])), 'every short address is flat and not itself an old address');
    ok(is_array($dl) && count($dl) === count(array_unique($dl)) && !array_filter($dl, fn($k) => isset($al[$k]) || str_contains($k, 'print')), 'detail_routes.php: short keys only, no print pages, no duplicates');
    $src = (string)file_get_contents($root . '/roots.php');
    ok(str_contains($src, 'function routeAliasRedirectFor(') && str_contains($src, 'function detailRoutePathFor(') && str_contains($src, 'canonicalRouteKey($key)'), 'roots.php has the alias + record-path routing');
    echo "
" . ($fail === 0 ? "[32mALL $pass PASSED[0m" : "[31m$fail FAILED[0m, $pass passed") . "
";
    exit($fail === 0 ? 0 : 1);
}
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
chdir($root);
require_once $root . '/roots.php';
global $routes;
$list    = require $root . '/core/detail_routes.php';
$aliases = $GLOBALS['__bms_route_aliases'];
$base    = getBasePath();

echo "\n── 1. The list ──\n";
$bad = [];
foreach ($list as $k) {
    if (!isset($routes[$k]) || !is_file($routes[$k])) $bad[] = "$k is not a page";
    if (isset($aliases[$k]))                          $bad[] = "$k is an old alias, list the short key";
    if (str_contains($k, 'print') || str_contains($k, '/')) $bad[] = "$k: print pages and prefixed keys are not listed";
    if (!preg_match('/\$_(GET|REQUEST)\[[\'"]id[\'"]\]/', (string)@file_get_contents($routes[$k] ?? ''))) $bad[] = "$k never reads \$_GET['id']";
}
ok(count($list) >= 45 && !$bad, count($list) . ' record pages listed, each a real page that reads its id' . ($bad ? ' — ' . implode('; ', array_slice($bad, 0, 5)) : ''));
ok(count($list) === count(array_unique($list)), 'no page listed twice');

echo "\n── 2. Which addresses become path-style ──\n";
ok(detailRoutePathFor('invoice_view', 'id=231') === $base . '/invoice_view/231', 'invoice_view?id=231 → /invoice_view/231');
ok(detailRoutePathFor('invoice_view', 'id=231&tab=payments&back=x') === $base . '/invoice_view/231?tab=payments&back=x', 'other query values stay in the query string');
ok(detailRoutePathFor('invoice_view', 'tab=payments&id=5') === $base . '/invoice_view/5?tab=payments', 'id can come anywhere in the query');
foreach (['id=0', 'id=-3', 'id=abc', 'id=', 'id=12abc', 'id=1.5', 'id=007x', 'id[]=3', 'id=99999999999999999999', ''] as $q)
    ok(detailRoutePathFor('invoice_view', $q) === null, "'$q' is left alone (not a plain record number)");
ok(detailRoutePathFor('expenses', 'id=3') === null, 'a list page is never changed');
ok(detailRoutePathFor('invoice_print', 'id=3') === null, 'a print page is never changed');
ok(routeAliasRedirectFor('customers/details', 'GET', 'id=12&tab=invoices') === $base . '/customer_details/12?tab=invoices', 'old address + id: one hop to /customer_details/12?tab=invoices');
ok(routeAliasRedirectFor('journal/view', 'HEAD', 'id=9') === $base . '/journal_details/9', 'HEAD behaves like GET');
ok(routeAliasRedirectFor('invoice_view', 'POST', 'id=5') === null, 'POST is never redirected');
ok(routeAliasRedirectFor('invoice_view', 'GET', '') === null, 'no id → served as it is');

echo "\n── 3. Real HTTP ──\n";
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
$http = function (string $path, string $method = 'GET') use ($port, &$cookie): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 90,
        'header' => ($cookie !== '' ? "Cookie: $cookie\r\n" : '') . ($method === 'POST' ? "Content-Type: application/x-www-form-urlencoded\r\n" : ''), 'content' => $method === 'POST' ? 'x=1' : '']]);
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

/** Relative links/calls in rendered HTML: they would break under /page/ID. */
$relative = function (string $html): array {
    $skip = '#^(/|https?:|//|\#|javascript:|mailto:|tel:|data:|blob:|\?)#i';
    $noise = fn($u) => $u === '' || preg_match('/[\s\[\]()\\\\^|{}$<>]|^[a-z-]+=$/i', $u);
    $h = preg_replace('#<!--.*?-->#s', '', $html);
    $found = [];
    if (preg_match_all('/\b(?:href|src|action)\s*=\s*["\']([^"\']*)["\']/i', $h, $m)) foreach ($m[1] as $u) if (!$noise($u) && !preg_match($skip, $u)) $found[] = "attr:$u";
    if (preg_match_all('/(?:fetch|\$\.(?:ajax|get|post|getJSON)|\.load|window\.open|location(?:\.href|\.assign|\.replace)?\s*=?)\s*\(?\s*(?:\{\s*url\s*:\s*)?["\']([^"\']+)["\']/i', $h, $m2)) foreach ($m2[1] as $u) if (!$noise($u) && !preg_match($skip, $u) && preg_match('#[./?]#', $u)) $found[] = "js:$u";
    if (preg_match_all('/\burl\s*:\s*["\']([^"\']+)["\']/i', $h, $m3)) foreach ($m3[1] as $u) if (!$noise($u) && !preg_match($skip, $u) && preg_match('#\.php|api/#', $u)) $found[] = "url:$u";
    return array_values(array_unique($found));
};

if ($up) {
    $opened = 0; $skipped = []; $problems = []; $rels = []; $tabbed = 0;
    foreach ($list as $key) {
        $real = null;
        for ($id = 1; $id <= 40 && !$real; $id++) {
            [$code, $loc, $body] = $http("/$key/$id");
            if ($code === 200 && strlen($body) > 4000 && !str_contains($body, 'location.replace') && !preg_match('/Fatal error|Uncaught /', $body)) $real = [$id, $body];
        }
        if (!$real) { $skipped[] = $key; continue; }
        [$id, $body] = $real;
        $opened++;
        $r = $relative($body);
        if ($r) $rels[$key] = $r;
        // ?id=N → /page/N (one hop, tab kept)
        [$c1, $l1] = $http("/$key?id=$id&tab=zz");
        if ($c1 !== 302 || $l1 !== "/$key/$id?tab=zz") $problems[] = "/$key?id=$id&tab=zz → $c1 $l1";
        else $tabbed++;
        // POST to the path form is served (not bounced back to ?id=)
        [$c2, $l2, $b2] = $http("/$key/$id", 'POST');
        if (in_array($c2, [301, 302], true) && str_contains($l2, 'id=')) $problems[] = "POST /$key/$id bounced to $l2";
        if (preg_match('/Fatal error|Uncaught /', $b2)) $problems[] = "POST /$key/$id: PHP error";
    }
    ok($opened >= 20, "$opened record pages opened with a real record at /page/N" . ($skipped ? ' (no record locally for: ' . implode(', ', $skipped) . ')' : ''));
    ok(!$rels, 'none of them has a relative link or call that would break under /page/N' . ($rels ? "\n      " . implode("\n      ", array_map(fn($k, $v) => "$k: " . implode(' | ', array_slice($v, 0, 3)), array_keys($rels), $rels)) : ''));
    ok(!$problems && $tabbed === $opened, "?id=N redirects to /page/N with the rest of the query kept, and POST is served, on all $opened" . ($problems ? "\n      " . implode("\n      ", array_slice($problems, 0, 6)) : ''));

    // Old address + id, one hop; unknown id falls back cleanly.
    [$c, $l] = $http('/customers/details?id=1');
    ok($c === 301 && $l === '/customer_details/1', "/customers/details?id=1 → $c $l");
    [$c, $l] = $http('/invoice_view/999999999');
    ok($c === 302 && $l === '/invoices', "/invoice_view/999999999 → back to the list ($c $l)");
    [$c, $l] = $http('/invoice_view/0');
    ok(!str_contains($l, 'invoice_view/0'), '/invoice_view/0 is not treated as a record');

    // Page-visit log names the page, not the number.
    $last = $pdo->query("SELECT action FROM activity_logs ORDER BY created_at DESC LIMIT 1");
    $row = $last ? $last->fetchColumn() : false;
    ok($row === false || !preg_match('/^View \d+$/', (string)$row), 'activity log does not record "View <number>" for a record page');
}
if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }

echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
