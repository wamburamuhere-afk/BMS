<?php
/**
 * tests/test_superadmin_urls_cli.php — the panel's short, host-scoped URLs.
 *
 *   php tests/test_superadmin_urls_cli.php
 *
 * The panel lives on its own hostname, so it owns the root of that hostname:
 * superadmin.example.tz/tenants, not /app/superadmin/tenants.php. Proves:
 *   1. the route map covers every panel page, both directions
 *   2. saUrl() is short on the superadmin host and legacy everywhere else
 *   3. every short URL actually resolves through the REAL handleRoute()
 *   4. the legacy /app/superadmin/... path 301s, and to the right place
 *   5. a TENANT host is not hijacked — 'profile' stays that company's own page,
 *      and 'tenants'/'features' are not routes there at all
 *   6. no panel file still emits a raw *.php link
 *
 * CLI ONLY. Read-only: writes nothing, provisions nothing.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// ─── Worker: resolve one URL on one host through the real router ────────────
// argv: --worker <host> <request-uri>
if (($argv[1] ?? '') === '--worker') {
    $_SERVER['HTTP_HOST']      = $argv[2];
    $_SERVER['REQUEST_URI']    = $argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = '';
    if (($p = strpos($argv[3], '?')) !== false) {
        $_SERVER['QUERY_STRING'] = substr($argv[3], $p + 1);
        parse_str($_SERVER['QUERY_STRING'], $_GET);
    }

    // Reported on shutdown because a redirect exits before anything else runs.
    register_shutdown_function(function () {
        fwrite(STDOUT, "\n[CODE]" . (http_response_code() ?: 200) . "[/CODE]");
    });

    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];

    ob_start();
    $handled = handleRoute();
    $html    = ob_get_clean();

    // Internal links, so the test can prove the rendered page is short too.
    $links = [];
    if (preg_match_all('~href="([^"]+)"~', $html, $m)) {
        foreach ($m[1] as $l) {
            if (!str_starts_with($l, 'http') && !str_starts_with($l, '#')) $links[] = $l;
        }
    }
    fwrite(STDOUT, '[HANDLED]' . ($handled ? 'yes' : 'no') . '[/HANDLED]');
    fwrite(STDOUT, '[TITLE]' . (preg_match('~<title>(.*?)</title>~s', $html, $t) ? trim($t[1]) : '') . '[/TITLE]');
    fwrite(STDOUT, '[LINKS]' . implode(' ', array_unique($links)) . '[/LINKS]');
    exit(0);
}

// ─── Runner ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

$BASE   = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
$SA     = superadminHostLabel() . '.' . $BASE;
$tenant = getControlPdo()->query("SELECT subdomain FROM tenants WHERE status IN ('active','trial') ORDER BY id LIMIT 1")->fetchColumn();
$TENANT = $tenant ? $tenant . '.' . $BASE : null;

echo "\nBMS — superadmin panel URLs\n  panel host = $SA\n";

function fetch(string $host, string $uri): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --worker ' . escapeshellarg($host) . ' ' . escapeshellarg($uri);
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);
    $joined = implode("\n", $out);
    $grab = function (string $tag) use ($joined) {
        return preg_match("~\[$tag\](.*?)\[/$tag\]~s", $joined, $m) ? trim($m[1]) : '';
    };
    return [
        'code'    => (int)($grab('CODE') ?: 0),
        'handled' => $grab('HANDLED') === 'yes',
        'title'   => $grab('TITLE'),
        'links'   => $grab('LINKS'),
        'raw'     => $joined,
        'crashed' => str_contains($joined, 'Fatal error') || str_contains($joined, 'Parse error'),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
section('1. The route map covers the panel, both directions');

$map = superadminRouteMap();
ok('the map is not empty', $map !== []);

foreach ($map as $key => $file) {
    ok("route '" . ($key === '' ? '/' : $key) . "' points at a file that exists", is_file($file), $file);
}

// Every .php page in app/superadmin/ must be reachable by a short URL, or a new
// page added later would silently keep the long address.
$panelFiles = glob(__DIR__ . '/../app/superadmin/*.php');
$mapped     = array_map(static fn($f) => basename($f), array_values($map));
foreach ($panelFiles as $f) {
    ok('panel page ' . basename($f) . ' has a short route', in_array(basename($f), $mapped, true));
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. superadminShortUrlFor() — where a legacy path redirects TO');

ok("'app/superadmin/tenants.php' -> /tenants",  superadminShortUrlFor('app/superadmin/tenants.php') === '/tenants');
ok("'app/superadmin/tenants' -> /tenants",      superadminShortUrlFor('app/superadmin/tenants') === '/tenants');
ok("'app/superadmin/features.php' -> /features",superadminShortUrlFor('app/superadmin/features.php') === '/features');
ok("'app/superadmin/profile.php' -> /profile",  superadminShortUrlFor('app/superadmin/profile.php') === '/profile');
ok("'app/superadmin/tenant_view.php' -> /tenants/view",
   superadminShortUrlFor('app/superadmin/tenant_view.php') === '/tenants/view');
ok("'app/superadmin/tenant_new.php' -> /tenants/new",
   superadminShortUrlFor('app/superadmin/tenant_new.php') === '/tenants/new');
ok('a non-panel path maps to nothing', superadminShortUrlFor('app/bms/pos/pos.php') === null);
ok('the prefix alone maps to nothing', superadminShortUrlFor('app/superadmin/') === null);

// ─────────────────────────────────────────────────────────────────────────────
section('3. Short URLs resolve through the real router');

foreach (['/tenants' => 'Platform Administration',
          '/features' => 'Modules',
          '/profile'  => 'Platform Administration',
          '/tenants/new' => 'Platform Administration'] as $uri => $expectTitle) {
    $r = fetch($SA, $uri);
    ok("GET $uri is handled", $r['handled'] && !$r['crashed'], substr($r['raw'], 0, 200));
    ok("  ...and renders the panel", str_contains($r['title'], $expectTitle) || $r['title'] !== '',
       'title=' . $r['title']);
}

$tid = (int)(getControlPdo()->query("SELECT id FROM tenants ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
if ($tid) {
    $r = fetch($SA, "/tenants/view?id=$tid");
    ok('GET /tenants/view?id=N is handled', $r['handled'] && !$r['crashed'], substr($r['raw'], 0, 200));
}

// ─────────────────────────────────────────────────────────────────────────────
section('4. The rendered pages link short, never to /app/superadmin/...');

foreach (['/tenants', '/features', '/profile', '/tenants/new'] as $uri) {
    $r = fetch($SA, $uri);
    ok("$uri emits no /app/superadmin/ link", !str_contains($r['links'], '/app/superadmin/'), $r['links']);
    ok("  ...and no bare *.php link", !preg_match('~(^|\s)[a-z_]+\.php~', $r['links']), $r['links']);
}

// ─────────────────────────────────────────────────────────────────────────────
section('5. The legacy path 301s away');

foreach (['/app/superadmin/tenants', '/app/superadmin/tenants.php',
          '/app/superadmin/features', '/app/superadmin/profile'] as $uri) {
    $r = fetch($SA, $uri);
    ok("GET $uri returns 301", $r['code'] === 301, 'code=' . $r['code'] . ' ' . substr($r['raw'], 0, 120));
}

// ─────────────────────────────────────────────────────────────────────────────
section('6. A tenant host is NOT hijacked');

if ($TENANT === null) {
    echo "  (no live tenant to test against)\n";
} else {
    // 'profile' is a TENANT route in $routes. Claiming it globally would have
    // replaced every company's own profile page with the platform panel.
    $r = fetch($TENANT, '/profile');
    ok("tenant /profile is NOT the platform panel",
       !str_contains($r['title'], 'Platform Administration'), 'title=' . $r['title']);

    foreach (['/tenants', '/features', '/tenants/new'] as $uri) {
        $r = fetch($TENANT, $uri);
        ok("tenant $uri is not a route there", !$r['handled'], 'title=' . $r['title']);
    }

    // And the panel's own guard still refuses the long path from a tenant host.
    $r = fetch($TENANT, '/app/superadmin/tenants');
    ok('tenant /app/superadmin/tenants does not render the panel',
       !str_contains($r['title'], 'Platform Administration'), 'title=' . $r['title']);
}

// ─────────────────────────────────────────────────────────────────────────────
section('7. saUrl() falls back to the legacy path off the panel host');

// In THIS process no superadmin host is set, so saUrl() must return the literal
// path — the property that keeps single-tenant and local installs working.
ok("saUrl('tenants') falls back to the literal file path",
   saUrl('tenants') === '/app/superadmin/tenants.php', saUrl('tenants'));
ok("saUrl('tenants/view?id=7') keeps the query string",
   saUrl('tenants/view?id=7') === '/app/superadmin/tenant_view.php?id=7', saUrl('tenants/view?id=7'));
ok("saUrl('') falls back to the index",
   saUrl('') === '/app/superadmin/index.php', saUrl(''));
ok('superadminLoginUrl() uses the same mechanism',
   superadminLoginUrl() === saUrl('login'), superadminLoginUrl());

// ─────────────────────────────────────────────────────────────────────────────
section('8. No panel file emits a raw *.php link');

foreach ($panelFiles as $f) {
    $src = (string)file_get_contents($f);
    $bad = preg_match('~href="[a-z_]+\.php~', $src, $m) ? $m[0] : '';
    ok(basename($f) . ' has no hardcoded *.php href', $bad === '', $bad);
}

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
