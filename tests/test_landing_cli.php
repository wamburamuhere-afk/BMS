<?php
/**
 * tests/test_landing_cli.php — the public website (landing.php), robots.txt, sitemap.xml and the
 * sign-up links into register.php.
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
 *   F. Redesign (2026-11-11): Products menu (Smart BMS; Smart POS → Retail shop / Restaurant), one
 *      language shown at a time, Retail page says nothing about restaurants and the Restaurant page
 *      nothing about shops, footer has no staff link, Pricing → sign-up at step 3, ?business=restaurant
 *   G. Translation: English and Kiswahili have the same wording keys, nothing empty, and no English
 *      sentence shows on a Kiswahili page (nor Kiswahili on an English page)
 * Needs multi-company mode on in includes/config.php (TENANT_MODE / TENANT_BASE_DOMAIN).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/plans.php";
require_once "$root/core/public_site.php";
require_once "$root/core/tenant_lifecycle_policy.php";
require_once "$root/core/landing_content.php";
require_once "$root/core/tenant_registration.php";   // selfRegistrationClosedReason

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
/** Visible text of <main> (tags and attributes removed). */
function mainText(string $html): string { preg_match('#<main[^>]*>(.*)</main>#s', $html, $m); return html_entity_decode(strip_tags($m[1] ?? ''), ENT_QUOTES, 'UTF-8'); }
/** Every leaf string of a nested array, by key path. */
function leaves(array $a, string $p = ''): array { $o = []; foreach ($a as $k => $v) { $kp = $p === '' ? (string)$k : "$p.$k"; if (is_array($v)) $o += leaves($v, $kp); else $o[$kp] = $v; } return $o; }

$base = tenantBaseDomain();
if (!tenantModeEnabled() || !$base) { echo "  \033[33m⚠ multi-company mode is off here — nothing to test\033[0m\n"; exit(0); }
$company = (string)getControlPdo()->query("SELECT subdomain FROM tenants WHERE status IN ('active','trial') ORDER BY id LIMIT 1")->fetchColumn();
$saHost = (getenv('SUPERADMIN_SUBDOMAIN') ?: 'superadmin') . '.' . $base;

// ── a public Smart POS plan, so the sign-up buttons render (throwaway; removed at the end) ──
$cp = getControlPdo();
$tmpPlanId = null;
$prevShow = $cp->query("SELECT show_on_registration FROM platform_products WHERE product_key = 'bms_shop'")->fetchColumn();
if ($prevShow !== false && !array_filter(listPublicOffers(null, true), fn($p) => $p['product_key'] === 'bms_shop')) {
    $cp->exec("UPDATE platform_products SET show_on_registration = 1 WHERE product_key = 'bms_shop'");
    $r = createPlan(['name' => 'Landing test POS ' . bin2hex(random_bytes(3)), 'description' => '', 'max_users' => 3, 'max_storage_mb' => 1000,
                     'feature_keys' => ['pos'], 'product_key' => 'bms_shop', 'tagline' => '', 'max_shops' => 2, 'tier_rank' => 1,
                     'is_public' => true, 'is_popular' => true, 'items' => [], 'addons' => [],
                     'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 30000, 'is_default' => 1]]]);
    $tmpPlanId = !empty($r['id']) ? (int)$r['id'] : null;
    if (!$tmpPlanId) echo "  \033[33m⚠ could not add a test Smart POS plan: " . ($r['error'] ?? '?') . "\033[0m\n";
}
register_shutdown_function(function () use ($cp, &$tmpPlanId, $prevShow) {
    if ($tmpPlanId) {
        foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $t) $cp->exec("DELETE FROM $t WHERE plan_id = $tmpPlanId");
        $cp->exec("DELETE FROM plans WHERE id = $tmpPlanId");
    }
    if ($prevShow !== false) $cp->prepare("UPDATE platform_products SET show_on_registration = ? WHERE product_key = 'bms_shop'")->execute([(int)$prevShow]);
});

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
$pages = array_keys(publicSitePages());
ok($pages === ['', 'smart-pos', 'smart-pos/retail', 'smart-pos/restaurant', 'smart-bms', 'pricing', 'faq', 'contact'], 'the site has the 8 pages');
$html = [];
foreach ($pages as $p) {
    foreach (['en', 'sw'] as $l) {
        $r = $get($base, '/' . $p . ($l === 'sw' ? '?lang=sw' : ''));
        $b = $html[$p][$l] = $r['body'];
        preg_match('#<title>([^<]+)</title>#', $b, $t);
        $okHead = strpos($b, '<meta name="description"') !== false && strpos($b, 'rel="canonical"') !== false
               && strpos($b, 'hreflang="sw"') !== false && strpos($b, 'hreflang="en"') !== false && strpos($b, '<html lang="' . $l . '"') !== false;
        ok($r['code'] === 200 && $okHead, "/$p [$l] 200, title \"" . html_entity_decode($t[1] ?? '?') . '", description, canonical, hreflang');
        ok(!preg_match('/\[(price|n|number|email|hours|address|time|phone)\]/', $b) && strpos($b, 'Notice:') === false && strpos($b, 'Warning:') === false && strpos($b, 'Fatal') === false,
           "/$p [$l] has no placeholder or PHP message");
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $b, $ld);
        $valid = array_filter($ld[1], fn($j) => is_array(json_decode($j, true)));
        if ($ld[1]) ok(count($valid) === count($ld[1]), "/$p [$l] structured data valid (" . count($ld[1]) . ')');
    }
}
$titles = [];
foreach ($pages as $p) { preg_match('#<title>([^<]+)</title>#', $html[$p]['en'], $t); $titles[] = $t[1] ?? ''; }
ok(count(array_unique($titles)) === count($titles), 'every page has its own title');
$home = $html['']['en'];
$days = tenantDefaultTrialDays();
ok($days <= 0 || strpos($home, sprintf('%d days free', $days)) !== false, "trial length shown is the live setting ($days days)");
$pr = $html['pricing']['en'];
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
ok($sm['code'] === 200 && $xml && count($xml->url) === 19, 'sitemap.xml valid: 9 pages in both languages + privacy (' . ($xml ? count($xml->url) : 0) . ' URLs)');
ok($xml && strpos($sm['body'], '/smart-pos/retail?lang=sw') !== false && strpos($sm['body'], '/smart-pos/restaurant<') !== false, 'sitemap lists the Retail and Restaurant pages');
ok($get($base, '/no-such-page')['code'] === 404 && $get($base, '/smart-pos/other')['code'] === 404, 'unknown paths 404');

// ─────────────────────────────────────────────────────────────────────────────
foreach (["B. Company address ($company.$base)" => "$company.$base", "C. Superadmin address ($saHost)" => $saHost] as $title => $host) {
    section($title);
    if ($host === ".$base") { ok(false, 'a local company to test with'); continue; }
    $r = $get($host, '/');
    ok($r['code'] === 302 && str_ends_with($r['h']['location'] ?? '', '/login'), '"/" still goes to the login page (' . $r['code'] . ')');
    ok(stripos($r['h']['x-robots-tag'] ?? '', 'noindex') !== false, 'X-Robots-Tag: noindex');
    foreach (['smart-pos', 'smart-pos/retail', 'smart-pos/restaurant', 'smart-bms', 'pricing', 'faq', 'contact', 'sitemap.xml'] as $p) ok($get($host, "/$p")['code'] === 404, "/$p → 404");
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
$v = publicSiteValidate(['platform_legal_name' => 'BJP TECHNOLOGIES (T) LTD', 'platform_public_url' => 'https://Bms.Example.co.tz/']);
ok($v['ok'] && $v['values']['platform_public_url'] === 'https://bms.example.co.tz', 'main website normalised (lowercase, no trailing slash)');
ok(!publicSiteValidate(['platform_public_url' => 'bms.example.co.tz'])['ok'] && !publicSiteValidate(['platform_public_url' => 'https://x.co.tz/page'])['ok']
   && !publicSiteValidate(['platform_public_url' => 'javascript:alert(1)'])['ok'], 'address without https://, with a path, or a script → refused');
ok(publicSiteDefaultLegalName() === 'BJP TECHNOLOGIES (T) LTD', 'default legal name until Superadmin sets one');
$s = (string)file_get_contents("$root/app/superadmin/settings.php");
ok(strpos($s, 'name="platform_legal_name"') !== false && strpos($s, 'name="platform_address"') !== false && strpos($s, 'name="platform_public_url"') !== false, 'Superadmin › Branding has the three fields');
$diff = trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' diff --stat origin/develop -- login.php 2>&1'));
ok($diff === '', 'login.php is unchanged');
$reg = $get($base, '/register');
ok($reg['code'] === 200 && strpos($reg['body'], 'name="pos_business"') !== false, 'sign-up page still served, with the Retail / Restaurant choice');

// ─────────────────────────────────────────────────────────────────────────────
section('F. Redesign: menus, pages, footer, sign-up links');
foreach (['en', 'sw'] as $l) {
    $b = $html[''][$l];
    ok(substr_count($b, 'class="lang-btn"') === 1 && preg_match('#class="lang-btn"[^>]*>.*?' . ($l === 'sw' ? 'SW' : 'EN') . '<svg#s', $b)
       && !preg_match('#class="lang-btn"[^>]*>.*?' . ($l === 'sw' ? 'EN' : 'SW') . '<svg#s', $b), "[$l] one language button showing only " . strtoupper($l));
    ok(preg_match('#id="langMenu" hidden>.*?English.*?Kiswahili#s', $b) === 1, "[$l] the other language is inside the dropdown, closed at first");
    ok(preg_match('#id="prodBtn"#', $b) && preg_match('#id="mega" hidden>(.*?)</div>\s*</div>\s*<div class="sheet"#s', $b, $mm)
       && strpos($mm[1], '/smart-bms') !== false && strpos($mm[1], '/smart-pos/retail') !== false && strpos($mm[1], '/smart-pos/restaurant') !== false,
       "[$l] Products menu: Smart BMS, Smart POS › Retail shop, Smart POS › Restaurant");
    ok(preg_match('#<nav class="nav"[^>]*>(.*?)</nav>#s', $b, $nv) && strpos($nv[1], 'href="/smart-pos') === false && strpos($nv[1], 'href="/smart-bms') === false,
       "[$l] Smart POS and Smart BMS are no longer separate menu items");
    ok(strpos($b, 'id="burger"') !== false && preg_match('#id="sheet" hidden>.*?/smart-pos/retail.*?/smart-pos/restaurant.*?/smart-bms.*?/pricing.*?data-open-login#s', $b) === 1,
       "[$l] phone menu: products, pages, Log in and Start free trial");
    preg_match('#<footer class="ftr">(.*?)</footer>#s', $b, $ft);
    $staff = landingCopy($l === 'sw', 0)['staff_login'];
    ok(isset($ft[1]) && strpos($ft[1], $staff) === false && strpos($ft[1], 'href="/login"') === false, "[$l] footer has no staff sign-in link");
    ok(substr_count($b, $staff) === 1 && preg_match('#id="loginDlg".*' . preg_quote(htmlspecialchars($staff, ENT_QUOTES), '#') . '#s', $b) === 1, "[$l] staff sign-in stays inside the Log in window only");
    ok(isset($ft[1]) && strpos($ft[1], htmlspecialchars(landingCopy($l === 'sw', 0)['foot_about'], ENT_QUOTES)) !== false
       && strpos($ft[1], '/smart-pos/retail') !== false && strpos($ft[1], '/smart-pos/restaurant') !== false && strpos($ft[1], '/smart-bms') !== false,
       "[$l] footer: the business line (with accounting) and product links");
}
$retail = mainText($html['smart-pos/retail']['en']); $rest = mainText($html['smart-pos/restaurant']['en']);
ok(!preg_match('/restaurant|kitchen|waiter|table|dish/i', $retail), 'Retail page says nothing about restaurants');
ok(!preg_match('/\bshops?\b|retail|stock receiving/i', $rest), 'Restaurant page says nothing about shops');
$retailSw = mainText($html['smart-pos/retail']['sw']); $restSw = mainText($html['smart-pos/restaurant']['sw']);
ok(!preg_match('/mgahawa|jikoni|mhudumu|meza/iu', $retailSw), 'Kiswahili Retail page says nothing about restaurants');
ok(!preg_match('/\bduka\b|maduka|rejareja/iu', $restSw), 'Kiswahili Restaurant page says nothing about shops');
// Accounting belongs to Smart BMS only: no Smart POS page or Smart POS wording claims it.
foreach (['smart-pos', 'smart-pos/retail', 'smart-pos/restaurant'] as $p) foreach (['en', 'sw'] as $l) {
    ok(!preg_match('/accounting|uhasibu|trial balance|balance sheet|mizania/iu', mainText($html[$p][$l])), "/$p [$l] does not claim accounting (Smart BMS only)");
}
foreach ([false, true] as $s) {
    $pos = json_encode([bmsProductCopy($s)['bms_shop'], bmsRestaurantCopy($s)], JSON_UNESCAPED_UNICODE);
    ok(!preg_match('/accounting|uhasibu|trial balance|mizania/iu', $pos), 'Smart POS sign-up wording (' . ($s ? 'sw' : 'en') . ') does not claim accounting');
    $hs = landingCopy($s, 14)['hero_sub']; $acc = $s ? 'uhasibu' : 'accounting';
    ok(mb_stripos($hs, $acc) !== false && mb_stripos($hs, 'Smart BMS') < mb_stripos($hs, $acc) && mb_stripos($hs, 'Smart POS') < mb_stripos($hs, 'Smart BMS'),
       'home line (' . ($s ? 'sw' : 'en') . '): accounting is named only with Smart BMS');
}
ok(strpos(mainText($html['smart-bms']['en']), 'Trial Balance') !== false && strpos(mainText($html['smart-bms']['en']), 'double-entry') !== false, 'Smart BMS page leads with accounting');
preg_match_all('#<tfoot><tr><td>[^<]+</td><td><span class="count" data-to="(\d+)">.*?data-to="(\d+)"#s', $html['smart-bms']['en'], $tb);
ok(isset($tb[1][0]) && $tb[1][0] === $tb[2][0], 'example Trial Balance: debits = credits (' . ($tb[1][0] ?? '?') . ')');
$posLive = (bool)array_filter(listPublicOffers(null, true), fn($p) => $p['product_key'] === 'bms_shop');
if ($posLive && selfRegistrationClosedReason() === null) {
    ok(strpos($html['smart-pos/retail']['en'], 'href="/register?product=bms_shop"') !== false, 'Retail page → sign-up for Smart POS (Retail)');
    ok(strpos($html['smart-pos/restaurant']['en'], 'href="/register?product=bms_shop&amp;business=restaurant"') !== false, 'Restaurant page → sign-up with Restaurant ticked');
    ok(strpos($html['smart-pos/restaurant']['sw'], 'href="/register?product=bms_shop&amp;business=restaurant&amp;lang=sw"') !== false, 'Kiswahili Restaurant page keeps Kiswahili on sign-up');
} else {
    echo "  \033[33m⚠ Smart POS has no public plan here (or sign-up is closed) — product pages show \"Ask for prices\" instead of sign-up\033[0m\n";
    ok(strpos($html['smart-pos/retail']['en'], 'Ask for prices') !== false && strpos($html['smart-pos/retail']['en'], '/register?product=bms_shop') === false, 'Retail page: no sign-up button without a live Smart POS plan');
}
preg_match_all('#data-plan-link href="([^"]+)"#', $pr, $pl);
ok($pl[1] && !array_filter($pl[1], fn($u) => !preg_match('#^/register\?product=[^&]+&amp;plan=[^&]+&amp;term=#', $u)), 'every plan on Pricing → sign-up with its product, plan and term (' . count($pl[1]) . ')');

// register.php: Restaurant from the link; plan from Pricing opens at step 3
$offers = listPublicOffers(null, true);
$anyPlan = $offers[0]['plans'][0]['plan_key'] ?? null;
$r1 = $get($base, '/register?business=restaurant')['body'];
ok(preg_match('#value="restaurant" checked#', $r1) && !preg_match('#value="retail" checked#', $r1), 'sign-up ?business=restaurant → Restaurant ticked');
ok(preg_match('#value="retail" checked#', $get($base, '/register')['body']) === 1, 'sign-up without it → Retail ticked (as before)');
ok(strpos($r1, 'const RESTAURANT = {') !== false && strpos($r1, 'Tell us about your restaurant') !== false, 'restaurant wording is on the sign-up page');
if ($anyPlan) {
    ok(strpos($get($base, '/register?plan=' . rawurlencode($anyPlan))['body'], 'const PLAN_FROM_LINK = true;') !== false, 'a live plan from Pricing → opens at the Shop/Company step');
}
ok(strpos($get($base, '/register?plan=no-such-plan')['body'], 'const PLAN_FROM_LINK = false;') !== false, 'an unknown plan → normal start (plan step)');
ok(strpos($get($base, '/register')['body'], 'const PLAN_FROM_LINK = false;') !== false, 'no plan → normal start');

// ─────────────────────────────────────────────────────────────────────────────
section('G. Translation: English and Kiswahili');
$en = leaves(landingCopy(false, 14)); $swc = leaves(landingCopy(true, 14));
ok(array_keys($en) === array_keys($swc), 'website wording: same keys in English and Kiswahili (' . count($en) . ')');
ok(!array_filter($swc, fn($v) => is_string($v) && trim($v) === ''), 'no empty Kiswahili text');
$same = array_keys(array_filter($en, fn($v, $k) => is_string($v) && $v === ($swc[$k] ?? null) && !preg_match('/_feats\.\d+\.0$/', $k) && !preg_match('/^(Smart (POS|BMS)|Trial Balance|Debit|Credit|FAQ|Email|Chart of accounts|Journals|[0-9 ,.:#×▲%-]+|[0-9-]+|TZS.*|.*TZS)$/u', $v), ARRAY_FILTER_USE_BOTH));
ok(!$same, 'every English line has its own Kiswahili' . ($same ? ' — same in both: ' . implode(', ', $same) : ''));
$rc = bmsRestaurantCopy(false); $rcs = bmsRestaurantCopy(true); $shop = bmsProductCopy(false)['bms_shop'];
ok(array_keys($rc) === array_keys($shop) && array_keys(leaves($rc)) === array_keys(leaves($rcs)), 'restaurant sign-up wording: same keys as the shop wording, in both languages');
ok(!array_filter(leaves($rcs), fn($v) => is_string($v) && trim($v) === ''), 'no empty Kiswahili restaurant text');
$fq = landingFaqs(false, 14); $fqs = landingFaqs(true, 14);
ok(array_keys(leaves($fq)) === array_keys(leaves($fqs)), 'FAQ: same questions in both languages');
// No English sentence on a Kiswahili page, and no Kiswahili on an English page (whole page text).
$enLines = array_filter(array_merge(leaves(landingCopy(false, $days)), leaves(bmsRestaurantCopy(false)), leaves(bmsProductCopy(false)), leaves(landingFaqs(false, $days))),
    fn($v) => is_string($v) && mb_strlen($v) >= 14 && preg_match('/[a-z]{4}/', $v));
$swLines = array_filter(array_merge(leaves(landingCopy(true, $days)), leaves(bmsRestaurantCopy(true)), leaves(bmsProductCopy(true)), leaves(landingFaqs(true, $days))),
    fn($v) => is_string($v) && mb_strlen($v) >= 14 && preg_match('/[a-z]{4}/', $v));
[$enLines, $swLines] = [array_diff($enLines, $swLines), array_diff($swLines, $enLines)];   // terms kept the same in both (e.g. "Chart of accounts") are not leaks
foreach ($pages as $p) {
    $tSw = html_entity_decode(strip_tags($html[$p]['sw']), ENT_QUOTES, 'UTF-8');
    $tEn = html_entity_decode(strip_tags($html[$p]['en']), ENT_QUOTES, 'UTF-8');
    $leakEn = array_values(array_filter($enLines, fn($v) => strpos($tSw, $v) !== false));
    $leakSw = array_values(array_filter($swLines, fn($v) => strpos($tEn, $v) !== false));
    ok(!$leakEn, "/$p [sw] has no English sentence" . ($leakEn ? ': "' . $leakEn[0] . '"' : ''));
    ok(!$leakSw, "/$p [en] has no Kiswahili sentence" . ($leakSw ? ': "' . $leakSw[0] . '"' : ''));
}
$rSw = html_entity_decode(strip_tags($get($base, '/register?business=restaurant&lang=sw')['body']), ENT_QUOTES, 'UTF-8');
ok(strpos($rSw, 'Tueleze kuhusu mgahawa wako') !== false, 'Kiswahili sign-up carries the Kiswahili restaurant wording');
