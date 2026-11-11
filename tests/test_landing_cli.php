<?php
/**
 * tests/test_landing_cli.php — the public website (landing.php), robots.txt and sitemap.xml.
 *
 *   php tests/test_landing_cli.php
 *
 * Starts its own `php -S` on this checkout and requests real pages with different Host headers:
 *   A. Platform root: every page 200 in English and Kiswahili, title/description/canonical/hreflang,
 *      structured data valid, prices = the live catalogue exactly, trial days live, no placeholders
 *   B. A company's own address: "/" still goes to its login; landing pages 404; robots "Disallow: /";
 *      sitemap 404; X-Robots-Tag noindex on every response
 *   C. Superadmin address: same as B
 *   D. Main-site setting: a second copy (Main public website = another address) is noindex,
 *      robots "Disallow: /", sitemap 404, canonical → the main site; setting restored after
 *   E. Branding validation; login.php untouched; register.php still served with the new choice
 * Needs multi-company mode on in includes/config.php (TENANT_MODE / TENANT_BASE_DOMAIN).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/plans.php";
require_once "$root/core/public_site.php";
require_once "$root/core/tenant_lifecycle_policy.php";

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$base = tenantBaseDomain();
if (!tenantModeEnabled() || !$base) { echo "  \033[33m⚠ multi-company mode is off here — nothing to test\033[0m\n"; exit(0); }
$company = (string)getControlPdo()->query("SELECT subdomain FROM tenants WHERE status IN ('active','trial') ORDER BY id LIMIT 1")->fetchColumn();
$saHost = (getenv('SUPERADMIN_SUBDOMAIN') ?: 'superadmin') . '.' . $base;

// ── own server ───────────────────────────────────────────────────────────────
$port = 18000 + random_int(0, 900);
$router = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "zz_landing_router_$port.php";
file_put_contents($router, '<?php
$root = ' . var_export($root, true) . ';
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$f = $root . $uri;
if ($uri !== "/" && is_file($f) && !preg_match("/\\.php$/", $f)) return false;
chdir($root);
if (preg_match("#^/(api|ajax|actions)/.+\\.php$#", $uri) && is_file($f)) { require $f; return; }
require $root . "/index.php";
');
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $router], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, $root);
register_shutdown_function(function () use (&$proc, $router) {
    global $pass, $fail;
    if (is_resource($proc)) proc_terminate($proc);
    @unlink($router);
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});
$get = function (string $host, string $path) use ($port): array {
    $ctx = stream_context_create(['http' => ['header' => "Host: $host\r\n", 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);
    $hdr = $http_response_header ?? [];
    preg_match('#HTTP/\S+\s+(\d+)#', $hdr[0] ?? '', $m);
    $h = [];
    foreach (array_slice($hdr, 1) as $line) { $p = strpos($line, ':'); if ($p) $h[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); }
    return ['code' => (int)($m[1] ?? 0), 'h' => $h, 'body' => (string)$body];
};
for ($i = 0; $i < 30; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(200000); }

// ─────────────────────────────────────────────────────────────────────────────
section("A. Platform root ($base)");
$pages = ['' => 'Smart BMS', 'smart-pos' => 'Smart POS', 'smart-bms' => 'Smart BMS', 'pricing' => 'pricing', 'faq' => 'questions', 'contact' => 'Contact'];
foreach ($pages as $p => $needle) {
    foreach (['en', 'sw'] as $l) {
        $r = $get($base, '/' . $p . ($l === 'sw' ? '?lang=sw' : ''));
        $b = $r['body'];
        preg_match('#<title>([^<]+)</title>#', $b, $t);
        $okHead = strpos($b, '<meta name="description"') !== false && strpos($b, 'rel="canonical"') !== false
               && strpos($b, 'hreflang="sw"') !== false && strpos($b, 'hreflang="en"') !== false && strpos($b, '<html lang="' . $l . '"') !== false;
        ok($r['code'] === 200 && $okHead, "/$p [$l] 200, title \"" . ($t[1] ?? '?') . '", description, canonical, hreflang');
        ok(!preg_match('/\[(price|n|number|email|hours|address|time|phone)\]/', $b), "/$p [$l] has no placeholder");
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $b, $ld);
        $valid = array_filter($ld[1], fn($j) => is_array(json_decode($j, true)));
        if ($ld[1]) ok(count($valid) === count($ld[1]), "/$p [$l] structured data valid (" . count($ld[1]) . ')');
    }
}
$home = $get($base, '/')['body'];
$days = tenantDefaultTrialDays();
ok($days <= 0 || strpos($home, sprintf('%d days free', $days)) !== false, "trial length shown is the live setting ($days days)");
$pr = $get($base, '/pricing')['body'];
$live = [];
foreach (listPublicOffers(null, true) as $prod) foreach ($prod['plans'] as $pl) {
    foreach ($pl['prices'] as $px) $live[] = 'TZS ' . number_format((int)$px['price_tzs']);
    ok(strpos($pr, htmlspecialchars($pl['name'], ENT_QUOTES)) !== false, "plan \"{$pl['name']}\" shown");
}
preg_match_all('#<p class="price">(TZS [0-9,]+)</p>#', $pr, $shown);
$a = array_unique($shown[1]); sort($a); $b = array_unique($live); sort($b);
ok($a === $b, 'every price on the page is a live catalogue price, and every live price is shown (' . count($b) . ')');
$rob = $get($base, '/robots.txt');
ok($rob['code'] === 200 && strpos($rob['body'], "Allow: /") !== false && strpos($rob['body'], 'Disallow: /api/') !== false && strpos($rob['body'], 'Sitemap: ') !== false, 'robots.txt allows the site, blocks app areas, names the sitemap');
$sm = $get($base, '/sitemap.xml');
$xml = @simplexml_load_string($sm['body']);
ok($sm['code'] === 200 && $xml && count($xml->url) >= 12, 'sitemap.xml valid with every page in both languages (' . ($xml ? count($xml->url) : 0) . ' URLs)');
ok($get($base, '/no-such-page')['code'] === 404, 'unknown path 404');

// ─────────────────────────────────────────────────────────────────────────────
foreach (["B. Company address ($company.$base)" => "$company.$base", "C. Superadmin address ($saHost)" => $saHost] as $title => $host) {
    section($title);
    if ($host === ".$base") { ok(false, 'a local company to test with'); continue; }
    $r = $get($host, '/');
    ok($r['code'] === 302 && str_ends_with($r['h']['location'] ?? '', '/login'), '"/" still goes to the login page (' . $r['code'] . ')');
    ok(stripos($r['h']['x-robots-tag'] ?? '', 'noindex') !== false, 'X-Robots-Tag: noindex');
    foreach (['smart-pos', 'pricing', 'faq', 'contact', 'sitemap.xml'] as $p) ok($get($host, "/$p")['code'] === 404, "/$p → 404");
    $rr = $get($host, '/robots.txt');
    ok(trim($rr['body']) === "User-agent: *\nDisallow: /", 'robots.txt: Disallow: /');
}

// ─────────────────────────────────────────────────────────────────────────────
section('D. A second copy points search engines to the main site');
$before = getPlatformSetting('platform_public_url', '');
try {
    setPlatformSetting('platform_public_url', 'https://main-site.example.co.tz');
    $r = $get($base, '/');
    ok(strpos($r['body'], '<meta name="robots" content="noindex, follow">') !== false && stripos($r['h']['x-robots-tag'] ?? '', 'noindex') !== false, 'pages are noindex');
    ok(strpos($r['body'], 'rel="canonical" href="https://main-site.example.co.tz/"') !== false, 'canonical → the main site');
    ok(trim($get($base, '/robots.txt')['body']) === "User-agent: *\nDisallow: /", 'robots.txt: Disallow: /');
    ok($get($base, '/sitemap.xml')['code'] === 404, 'no sitemap');
} finally {
    setPlatformSetting('platform_public_url', (string)$before);
}
ok((string)getPlatformSetting('platform_public_url', '') === (string)$before, 'setting restored');

// ─────────────────────────────────────────────────────────────────────────────
section('E. Settings, login and sign-up');
$v = publicSiteValidate(['platform_legal_name' => 'BJP TECHNOLOGIES (L) LTD', 'platform_public_url' => 'https://Bms.Example.co.tz/']);
ok($v['ok'] && $v['values']['platform_public_url'] === 'https://bms.example.co.tz', 'main website normalised (lowercase, no trailing slash)');
ok(!publicSiteValidate(['platform_public_url' => 'bms.example.co.tz'])['ok'] && !publicSiteValidate(['platform_public_url' => 'https://x.co.tz/page'])['ok']
   && !publicSiteValidate(['platform_public_url' => 'javascript:alert(1)'])['ok'], 'address without https://, with a path, or a script → refused');
ok(publicSiteDefaultLegalName() === 'BJP TECHNOLOGIES (L) LTD', 'default legal name until Superadmin sets one');
$s = (string)file_get_contents("$root/app/superadmin/settings.php");
ok(strpos($s, 'name="platform_legal_name"') !== false && strpos($s, 'name="platform_address"') !== false && strpos($s, 'name="platform_public_url"') !== false, 'Superadmin › Branding has the three fields');
$diff = trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' diff --stat origin/develop -- login.php 2>&1'));
ok($diff === '', 'login.php is unchanged');
$reg = $get($base, '/register');
ok($reg['code'] === 200 && strpos($reg['body'], 'name="pos_business"') !== false, 'sign-up page still served, with the Retail / Restaurant choice');
