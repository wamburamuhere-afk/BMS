<?php
/**
 * Clean URLs — one page, one short address (core/route_aliases.php).
 *   php tests/test_clean_urls_cli.php
 *
 * Checks the alias list itself, getUrl(), the old-address redirect, and then
 * starts `php -S` with this file as router and opens every short address over
 * real HTTP as a logged-in admin, so a page that breaks under its new name
 * fails here; every old address must answer a real 301. Exit 0 = pass.
 */

$root = dirname(__DIR__);

// ─── Web-server router: this same file, run by `php -S`, serves the app as a
// logged-in admin (user id in BMS_TEST_ADMIN), like Apache + index.php would.
if (PHP_SAPI === 'cli-server') {
    $path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file($root . $path) && !str_ends_with($path, '.php')) return false;
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)getenv('BMS_TEST_ADMIN'); $_SESSION['username'] = 'admin'; $_SESSION['role'] = 'admin';
    $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true; $_SESSION['user_lang'] = 'en';
    $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    chdir($root);
    require $root . '/index.php';
    return true;
}

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }

$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
chdir($root);
require_once $root . '/roots.php';
global $routes;
$aliases = $GLOBALS['__bms_route_aliases'] ?? null;

echo "\n── 1. The alias list ──\n";
ok(is_array($aliases) && count($aliases) > 100, 'core/route_aliases.php loads (' . (is_array($aliases) ? count($aliases) : 0) . ' old addresses)');
$bad = [];
foreach ($aliases as $old => $new) {
    if (!isset($routes[$old]))                       $bad[] = "$old is not a route";
    elseif (!isset($routes[$new]))                   $bad[] = "$new not registered";
    elseif ($routes[$old] !== $routes[$new])         $bad[] = "$old and $new open different files";
    if (isset($aliases[$new]))                       $bad[] = "$new is itself an old address (chain)";
    if (preg_match('#^(api|ajax|actions)/#', $old . '|' . $new)) $bad[] = "$old: api/ajax/actions never aliased";
    if (str_contains($new, '/'))                     $bad[] = "$new still has a folder prefix";
}
ok(!$bad, 'every old address and its short address open the same file, no chains, no api/ajax/actions' . ($bad ? ' — ' . implode('; ', array_slice($bad, 0, 5)) : ''));

$hidden = simpleModeHiddenPageKeys();
$looser = [];
foreach ($aliases as $old => $new) {
    $fo = featureForPageKey($old); $fn = featureForPageKey($new); sort($fo); sort($fn);
    if ($fo && $fo !== $fn) $looser[] = "$old→$new modules [" . implode(',', $fo) . "]→[" . implode(',', $fn) . "]";
    if (in_array($old, $hidden, true) && !in_array($new, $hidden, true)) $looser[] = "$old→$new no longer hidden in Simple Mode";
}
ok(!$looser, 'no short address is less guarded than its old address' . ($looser ? ' — ' . implode('; ', $looser) : ''));

// The restaurant screens are the ones the owner asked about by name.
foreach (['restaurant/purchases' => 'purchases', 'restaurant/menu' => 'menu', 'restaurant/tables-board' => 'tables-board',
          'restaurant/sales-report' => 'restaurant-sales', 'pos/dashboard' => 'pos_dashboard', 'customers/details' => 'customer_details'] as $o => $n) {
    ok(($aliases[$o] ?? null) === $n, "$o → $n");
}

echo "\n── 2. getUrl() prints the short address ──\n";
$base = getBasePath();
ok(getUrl('restaurant/purchases') === $base . '/purchases', "getUrl('restaurant/purchases') = " . getUrl('restaurant/purchases'));
ok(getUrl('/restaurant/menu') === $base . '/menu', 'leading slash handled');
ok(getUrl('accounts/expenses.php') === $base . '/expenses', '.php form resolves to the short address');
ok(getUrl('pos/dashboard?tab=history') === $base . '/pos_dashboard?tab=history', 'query string after the key is kept');
ok(getUrl('crm/leads#board') === $base . '/crm_leads#board', '#fragment after the key is kept');
ok(getUrl('expenses') === $base . '/expenses', 'an address with no alias is unchanged');
ok(getUrl('api/pos/get_sales') === $base . '/api/pos/get_sales', 'api paths untouched');

echo "\n── 3. Old address redirects (GET only, query kept) ──\n";
ok(routeAliasRedirectFor('restaurant/purchases', 'GET', '') === $base . '/purchases', 'GET restaurant/purchases → /purchases');
ok(routeAliasRedirectFor('customers/details', 'GET', 'id=12&tab=invoices') === $base . '/customer_details?id=12&tab=invoices', 'query string travels with the redirect');
ok(routeAliasRedirectFor('restaurant/menu', 'HEAD', '') === $base . '/menu', 'HEAD redirects too');
ok(routeAliasRedirectFor('restaurant/menu', 'POST', '') === null, 'POST to an old address is served, never redirected');
ok(routeAliasRedirectFor('menu', 'GET', '') === null, 'the short address itself is served');
ok(routeAliasRedirectFor('expenses', 'GET', 'x=1') === null, 'non-aliased page untouched');

$src = (string)file_get_contents($root . '/roots.php');
$posAlias = strpos($src, 'routeAliasRedirectFor($clean_uri');
$posMap   = strpos($src, '// 1. Map lookup');
ok($posAlias !== false && $posMap !== false && $posAlias < $posMap, 'handleRoute() checks the alias before serving the page');

echo "\n── 4. Real HTTP: every short address opens as admin, old ones redirect ──\n";
require_once $root . '/includes/config.php';
$admin = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
ok($admin > 0, "admin user found (#$admin)");

$port = 18000 + random_int(0, 900);
$env  = array_merge(getenv(), ['BMS_TEST_ADMIN' => (string)$admin]);
$srv  = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $nul = (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'), 'w'], 2 => ['file', $nul, 'w']], $pipes, $root, $env);
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) { usleep(100000); $s = @fsockopen('127.0.0.1', $port); if ($s) { fclose($s); $up = true; } }
ok($up, "test web server started on port $port");

/** One request, no redirect following: [status, Location, body]. */
$http = function (string $path, string $method = 'GET') use ($port): array {
    $ctx = stream_context_create(['http' => ['method' => $method, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 60,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $method === 'POST' ? 'x=1' : '']]);
    $body = (string)@file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $hdr  = $http_response_header ?? [];
    $code = preg_match('#^HTTP/\S+\s+(\d+)#', $hdr[0] ?? '', $m) ? (int)$m[1] : 0;
    $loc  = '';
    foreach ($hdr as $h) if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9));
    return [$code, $loc, $body];
};

if ($up) {
    $targets = array_values(array_unique(array_values($aliases)));
    $broken = []; $opened = 0; $sentOn = [];
    // Pages that need a record id answer this way when opened bare — same as under their old address.
    $needsId = ['zreport' => [200, 'Invalid shift ID'], 'field_visit_details' => [404, '<body']];
    foreach ($targets as $key) {
        [$code, $loc, $body] = $http('/' . $key);
        if ($code >= 500 || preg_match('/(Fatal error|Uncaught |Parse error)[^\n<]{0,200}/', $body, $m)) {
            $broken[] = "/$key → $code " . trim($m[0] ?? '');
        } elseif (isset($needsId[$key])) {
            if ($code === $needsId[$key][0] && str_contains($body, $needsId[$key][1])) $opened++;
            else $broken[] = "/$key → HTTP $code (expected its no-id answer)";
        } elseif ($code === 200 && stripos($body, '<body') !== false && strlen($body) > 5000) {
            $opened++;
        } elseif (in_array($code, [301, 302, 303], true)) {
            if (preg_match('#/(login|unauthorized)#', $loc)) $broken[] = "/$key → sent to $loc";
            else $sentOn[] = "/$key → $loc";   // e.g. a details page with no id goes back to its list
        } else {
            $broken[] = "/$key → HTTP $code, " . strlen($body) . ' bytes';
        }
    }
    ok(!$broken, "$opened of " . count($targets) . ' short addresses open a full page; none fail' . ($broken ? "\n      " . implode("\n      ", $broken) : ''));
    if ($sentOn) echo "      (sent on by the page itself, e.g. no id given: " . implode(', ', $sentOn) . ")\n";

    // Links printed on real pages use the short addresses.
    [$code, , $body] = $http('/restaurant');
    ok($code === 200 && !preg_match('#href="/restaurant/[a-z]#', $body), 'Restaurant hub prints no /restaurant/… links (HTTP ' . $code . ')');
    [$code, , $body] = $http('/purchases');
    ok($code === 200 && !str_contains($body, '/restaurant/purchases'), '/purchases opens and its own links say /purchases');
    [$code, , $body] = $http('/customers');
    ok($code === 200 && !preg_match('#href="[^"]*/customers/(details|view|groups)#', $body), 'Customers page links use /customer_details, /customer_groups');

    // Old addresses: a real 301 to the short one, query kept.
    $wrong = [];
    foreach ($aliases as $old => $new) {
        [$code, $loc] = $http('/' . $old . '?probe=1');
        if ($code !== 301 || $loc !== '/' . $new . '?probe=1') $wrong[] = "/$old → $code $loc";
    }
    ok(!$wrong, count($aliases) . ' old addresses answer 301 to their short address with the query kept' . ($wrong ? "\n      " . implode("\n      ", array_slice($wrong, 0, 10)) : ''));

    [$code, $loc, $body] = $http('/restaurant/menu', 'POST');
    ok($code !== 301 && !preg_match('/(Fatal error|Uncaught )/', $body), "POST /restaurant/menu is served, not redirected (HTTP $code)");

    [$code, $loc] = $http('/accounts/expenses.php?x=2');
    ok($code === 301 && $loc === '/expenses?x=2', "old .php address goes to the short one in one hop ($loc)");
}
if (is_resource($srv)) { proc_terminate($srv); proc_close($srv); }

echo "\n" . ($fail === 0 ? "\033[32mALL $pass PASSED\033[0m" : "\033[31m$fail FAILED\033[0m, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
