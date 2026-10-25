<?php
/**
 * tests/test_support_center_phase2_cli.php — Help & Support Center, Phase 2
 * (support_center_plan.md): menu-path mirror, article rendering + XSS safety,
 * validation, articles/categories data layer, superadmin actions + pages.
 *
 *   php tests/test_support_center_phase2_cli.php
 *
 * CLI ONLY. Snapshots support_articles / support_categories and restores them afterwards.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

if (($argv[1] ?? '') === '--endpoint') {
    $file = (string)$argv[2];
    $post = json_decode((string)base64_decode((string)$argv[3]), true) ?: [];
    $_SERVER['REQUEST_METHOD'] = (string)($argv[4] ?? 'POST');
    $_SERVER['HTTP_HOST']      = (string)($argv[5] ?? 'localhost');
    $_SERVER['REQUEST_URI']    = '/' . $file;
    if ((string)($argv[6] ?? '0') === '1') {
        require_once __DIR__ . '/../core/superadmin_auth.php';
        require_once __DIR__ . '/../helpers.php';
        superadminSessionReady();
        require_once __DIR__ . '/../core/control_db.php';
        $row = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($row) $_SESSION['superadmin_id'] = (int)$row['id'];
        if (!array_key_exists('_csrf', $post)) $post['_csrf'] = csrf_token();
    }
    $_POST = $post;
    require __DIR__ . '/../' . $file;
    exit(0);
}
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST'] = (string)$argv[2];
    $uri = (string)$argv[3];
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING'] = (string)parse_url($uri, PHP_URL_QUERY);
    parse_str($_SERVER['QUERY_STRING'], $_GET);
    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    superadminSessionReady();
    $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
    if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];
    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/support_articles.php';
require_once __DIR__ . '/../core/superadmin_auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Help & Support Center, Phase 2\n";
$root = dirname(__DIR__);
$c = getControlPdo();
$BASE = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
define('SA_HOST', superadminHostLabel() . '.' . $BASE);

// Snapshot + restore the two tables this suite writes to.
$snapA = $c->query('SELECT * FROM support_articles')->fetchAll(PDO::FETCH_ASSOC);
$snapC = $c->query('SELECT * FROM support_categories')->fetchAll(PDO::FETCH_ASSOC);
register_shutdown_function(function () use ($c, $snapA, $snapC) {
    foreach (['support_articles' => $snapA, 'support_categories' => $snapC] as $t => $rows) {
        $c->exec("DELETE FROM $t");
        foreach ($rows as $r) {
            $cols = array_keys($r);
            $c->prepare("INSERT INTO $t (`" . implode('`,`', $cols) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($r));
        }
    }
});

function endpoint(string $file, array $post, array $o = []): array {
    $cmd = 'php ' . escapeshellarg(__FILE__) . ' --endpoint ' . escapeshellarg($file) . ' '
         . escapeshellarg(base64_encode(json_encode($post))) . ' ' . escapeshellarg($o['method'] ?? 'POST') . ' '
         . escapeshellarg($o['host'] ?? SA_HOST) . ' ' . escapeshellarg(!empty($o['auth']) ? '1' : '0');
    $out = []; exec($cmd . ' 2>&1', $out);
    $raw = implode("\n", $out);
    if (str_contains($raw, 'Fatal error') || str_contains($raw, 'Parse error')) $raw = 'WORKER_CRASHED: ' . $raw;
    $j = json_decode($raw, true);
    return ['raw' => $raw, 'json' => is_array($j) ? $j : null];
}
function refused(array $r, string $expect = ''): bool {
    if (str_contains($r['raw'], 'WORKER_CRASHED') || str_contains($r['raw'], '"success":true')) return false;
    return $expect === '' || str_contains($r['raw'], $expect);
}
function route(string $uri): string {
    $out = []; exec('php ' . escapeshellarg(__FILE__) . ' --route ' . escapeshellarg(SA_HOST) . ' ' . escapeshellarg($uri) . ' 2>&1', $out);
    return implode("\n", $out);
}
const ART = 'actions/superadmin_support_articles.php';

// ─────────────────────────────────────────────────────────────────────────────
section('1. Lint');
foreach (['core/help_nav.php', 'core/support_articles.php', 'core/support.php', 'app/superadmin/support.php',
          'app/superadmin/support_article.php', 'actions/superadmin_support_articles.php', 'core/superadmin_auth.php',
          'scripts/setup_control_db.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. Menu mirror matches the live Smart POS demo shop (verified 2026-10-24)');
$pa = helpPreviewContext('pos', 'admin');
$expect = [
    'pos' => 'Sell', 'warehouses' => 'Shop › Shops', 'restock' => 'Shop › Receive Stock', 'expenses' => 'Expenses',
    'dashboard' => 'My Business › Dashboard', 'customers' => 'My Business › Customers', 'suppliers' => 'My Business › Suppliers',
    'products' => 'My Business › Shop Products', 'services' => 'My Business › Service',
    'sales_report' => 'Reports › Sales Report', 'inventory_report' => 'Reports › Inventory Report', 'expense_report' => 'Reports › Expense Report',
    'pos_user_sales_report' => 'Reports › Sales by User Report', 'pos_profit_report' => 'Reports › Profit Report',
    'subscription' => 'Subscription', 'system_settings' => 'Settings › Admin', 'pos_config_settings' => 'Settings › Selling Settings',
    'help' => 'Your name (top right) › Help',
];
foreach ($expect as $k => $p) ok("Smart POS admin: $k → \"$p\"", helpNavPath($k, $pa) === $p, var_export(helpNavPath($k, $pa), true));
foreach (['invoices', 'quotations', 'payroll', 'purchase_report', 'product_analysis', 'grn', 'income_statement'] as $k) {
    ok("Smart POS admin: $k not in the menu (live shop does not show it)", helpNavPath($k, $pa) === null);
}

section('3. Smart BMS and staff paths follow the header rules');
$ba = helpPreviewContext('bms', 'admin');
foreach (['pos' => 'Sales › POS', 'warehouses' => 'Inventory › Warehouses', 'invoices' => 'Finance › Invoices', 'expenses' => 'Finance › Expenses',
          'payroll' => 'Operations › Payroll', 'quotations' => 'Sales › Quotations', 'products' => 'Core › Inventory Products',
          'pos_config_settings' => 'Settings › POS Settings', 'income_statement' => 'Reports › Income Statement'] as $k => $p) {
    ok("Smart BMS admin: $k → \"$p\"", helpNavPath($k, $ba) === $p, var_export(helpNavPath($k, $ba), true));
}
$bs = helpPreviewContext('bms', 'staff');
ok('BMS staff with one report: Reports collapses to the item ("Sales Report")', helpNavPath('sales_report', $bs) === 'Sales Report', var_export(helpNavPath('sales_report', $bs), true));
ok('BMS staff: no Admin settings', helpNavPath('system_settings', $bs) === null);
$ps = helpPreviewContext('pos', 'staff');
ok('POS staff without Receive Stock: Shop is a direct link', helpNavPath('warehouses', $ps) === 'Shop');
ok('POS staff: Expenses hidden (role)', helpNavPath('expenses', $ps) === null);
// POS alone in a non-simple company → direct link; companions never count.
$only = ['simple' => false, 'shop' => false, 'admin' => false, 'tenant' => true, 'projects' => false, 'ai' => false,
         'can' => fn($k) => in_array($k, ['pos', 'invoices', 'warehouses', 'products'], true), 'feature' => fn() => false, 't' => fn($s) => $s];
ok('non-simple: Sales with only POS counted collapses to "POS" (Invoices there is a companion)', helpNavPath('pos', $only) === 'POS', var_export(helpNavPath('pos', $only), true));
ok('non-simple: Inventory with products + warehouses stays a dropdown', helpNavPath('warehouses', $only) === 'Inventory › Warehouses');
ok('non-simple: Finance with only Invoices collapses to "Invoices"', helpNavPath('invoices', $only) === 'Invoices');
$t = $only; $t['t'] = fn($s) => ['Inventory' => 'Bidhaa', 'Warehouses' => 'Maghala'][$s] ?? $s;
ok('labels go through t() (Swahili menu)', helpNavPath('warehouses', $t) === 'Bidhaa › Maghala');
ok('a page key that equals a PHP function name is not called as a function', helpNavPath('pos', $pa) === 'Sell');

section('4. Drift guard — every menu page in header.php is known to the mirror');
$hdr = file_get_contents("$root/header.php");
$navStart = strpos($hdr, '<!-- Core Modules');
$navEnd = strpos($hdr, '<!-- User Account');
ok('header.php nav markers found', $navStart !== false && $navEnd !== false && $navEnd > $navStart);
$navSrc = preg_replace(['~<!--.*?-->~s', '~/\*.*?\*/~s', '~(?m)^\s*//.*$~', '~//[^\n]*~'], '', substr($hdr, $navStart, $navEnd - $navStart));
preg_match_all("/canView\\('([a-z0-9_]+)'\\)/", $navSrc, $mm);
$headerKeys = array_unique($mm[1]);
$asked = [];
foreach ([false, true] as $simple) {
    $rec = ['simple' => $simple, 'shop' => false, 'admin' => true, 'tenant' => true, 'projects' => true, 'ai' => true,
            'can' => function ($k) use (&$asked) { $asked[$k] = true; return true; }, 'feature' => fn() => true, 't' => fn($s) => $s];
    foreach (array_keys(helpNavCatalog()) as $k) helpNavLocate($k, $rec);
}
$missing = array_values(array_diff($headerKeys, array_keys($asked)));
ok('header has ' . count($headerKeys) . ' menu permission keys, all mirrored', !$missing, 'missing: ' . implode(', ', $missing));
ok('wLabel pairs in the header match the mirror (POS/Sell, Warehouses/Shops, Inventory/Shop)',
   str_contains($hdr, "wLabel('POS', 'Sell')") && str_contains($hdr, "wLabel('Warehouses', 'Shops')") && str_contains($hdr, "wLabel('Inventory', 'Shop')"));

// ─────────────────────────────────────────────────────────────────────────────
section('5. Rendering — formatting and placeholders');
$html = supportRenderBody("Intro **bold** text.\n\n1. Open {nav:warehouses}.\n2. Add a {shop}.\n- one\n- two\nTip: Each {shop} has stock.", $pa);
ok('numbered steps become <ol><li>', str_contains($html, '<ol>') && substr_count($html, '<li>') === 4);
ok('bullets become <ul>', str_contains($html, '<ul>'));
ok('tip box rendered', str_contains($html, '<div class="help-tip"><strong>Tip:</strong>'));
ok('**bold** rendered', str_contains($html, '<strong>bold</strong>'));
ok('{nav:warehouses} → Shop › Shops chip (Smart POS)', str_contains($html, '<span class="help-path">Shop › Shops</span>'));
ok('{shop} → shop (Smart POS)', str_contains($html, 'Add a shop.'));
$htmlB = supportRenderBody("1. Open {nav:warehouses} and add a {Shop}.", $ba);
ok('same text in Smart BMS → Inventory › Warehouses / Warehouse', str_contains($htmlB, 'Inventory › Warehouses') && str_contains($htmlB, 'add a Warehouse'));
ok('{nav:invoices} for Smart POS falls back to the page name, never crashes', str_contains(supportRenderBody('{nav:invoices}', $pa), '<span class="help-path">Invoices</span>'));
ok('title: placeholders resolved as plain text', supportTitleText('Add a new {shop} in {nav:warehouses}', $pa) === 'Add a new shop in Shop › Shops');

section('6. Rendering — XSS and injection safety');
$evil = [
    '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '"><svg/onload=alert(1)>', "javascript:alert(1)",
    '{nav:<script>}', '**<b onmouseover=alert(1)>x</b>**', "1. <iframe src=//evil>", "Tip: <a href='javascript:x'>y</a>",
    '{nav:pos" onclick="x}', "\u{202E}<script>", '&lt;script&gt;',
];
foreach ($evil as $i => $payload) {
    $out = supportRenderBody($payload, $pa) . supportRenderTitle($payload, $pa);
    $tags = [];
    preg_match_all('/<\s*([a-z0-9]+)/i', $out, $tm);
    $bad = array_diff(array_map('strtolower', $tm[1]), ['p', 'ol', 'ul', 'li', 'strong', 'span', 'div']);
    ok("payload #$i produces only allow-listed tags", !$bad, implode(',', $bad) . ' in ' . $out);
    ok("payload #$i has no live event handler / link inside a real tag", !preg_match('/<[a-z][^>]*(\son[a-z]+\s*=|\shref\s*=|\ssrc\s*=)/i', $out), $out);
}
ok('escaped entity stays escaped (no double-decoding)', str_contains(supportRenderBody('&lt;script&gt;', $pa), '&amp;lt;script&amp;gt;'));

// ─────────────────────────────────────────────────────────────────────────────
section('7. Validation');
$base = ['title_en' => 'A good title', 'body_en' => '1. Do this first.', 'page_keys' => ['pos']];
ok('valid article accepted', supportValidateArticle($base)['ok'], json_encode(supportValidateArticle($base)['errors']));
ok('missing title refused', isset(supportValidateArticle(['title_en' => ''] + $base)['errors']['title_en']));
ok('too-short body refused', isset(supportValidateArticle(['body_en' => 'x'] + $base)['errors']['body_en']));
ok('body over 20,000 chars refused', isset(supportValidateArticle(['body_en' => str_repeat('a', 20001)] + $base)['errors']['body_en']));
ok('unknown page key refused', isset(supportValidateArticle(['page_keys' => ['pos', 'drop_table']] + $base)['errors']['page_keys']));
ok('more than 10 pages refused', isset(supportValidateArticle(['page_keys' => array_slice(array_keys(helpNavCatalog()), 0, 11)] + $base)['errors']['page_keys']));
ok('unknown {nav:…} inside the body refused', isset(supportValidateArticle(['body_en' => 'Open {nav:nope_page} now.'] + $base)['errors']['body_en']));
foreach (['http://www.youtube.com/watch?v=1' => 'plain http', 'javascript:alert(1)' => 'javascript:', 'https://evil.example/x' => 'other host',
          'https://user:pw@youtube.com/x' => 'credentials in URL', 'https://youtube.com.evil.example/' => 'look-alike host'] as $u => $why) {
    ok("video URL refused ($why)", isset(supportValidateArticle(['video_url' => $u] + $base)['errors']['video_url']));
}
ok('YouTube https accepted', supportValidateArticle(['video_url' => 'https://www.youtube.com/watch?v=abc'] + $base)['ok']);
ok('non-numeric category refused', isset(supportValidateArticle(['category_id' => '1 OR 1=1'] + $base)['errors']['category_id']));
ok('non-existent category refused (DB check)', isset(supportValidateArticle(['category_id' => '99999999'] + $base, $c)['errors']['category_id']));
ok('category: unknown icon refused', isset(supportValidateCategory(['name_en' => 'Ok name', 'icon' => 'x" onclick="y'])['errors']['icon']));
ok('category: valid accepted', supportValidateCategory(['name_en' => 'Ok name', 'icon' => 'box', 'sort_order' => '5'])['ok']);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Data layer — create, update, publish, archive, list, visibility, seed');
$c->exec('DELETE FROM support_articles'); $c->exec('DELETE FROM support_categories');
[$sc, $sa] = supportSeedDefaults($c, controlDbName());
ok('seed on empty tables: 8 categories + 16 draft articles', $sc === 8 && $sa === 16, "$sc / $sa");
[$sc2, $sa2] = supportSeedDefaults($c, controlDbName());
ok('seed again: nothing added (idempotent)', $sc2 === 0 && $sa2 === 0);
ok('seeded articles are drafts (BJP reviews before tenants see them)', (int)$c->query("SELECT COUNT(*) FROM support_articles WHERE status <> 'draft'")->fetchColumn() === 0);
$seedErrors = [];
foreach ($c->query('SELECT * FROM support_articles')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['page_keys'] = json_decode($row['page_keys'], true);
    $r = supportValidateArticle($row, $c);
    if (!$r['ok']) $seedErrors[] = $row['title_en'] . ': ' . json_encode($r['errors']);
}
ok('every seeded article passes validation (known pages, valid text)', !$seedErrors, implode(' | ', $seedErrors));

$catId = supportCategorySave(['name_en' => 'Test cat', 'name_sw' => '', 'icon' => 'box', 'sort_order' => 1], null);
$v = supportValidateArticle(['title_en' => 'Payroll how-to', 'body_en' => '1. Open {nav:payroll}.', 'page_keys' => ['payroll'], 'category_id' => (string)$catId], $c)['values'];
$aid = supportArticleSave($v, null, 1);
ok('new article saved as draft', supportArticleGet($aid)['status'] === 'draft');
supportArticleSetStatus($aid, 'published', 1);
ok('published', supportArticleGet($aid)['status'] === 'published');
ok('unknown status refused', supportArticleSetStatus($aid, 'deleted', 1) === false && supportArticleGet($aid)['status'] === 'published');
$v['title_en'] = 'Payroll how-to (edited)';
supportArticleSave($v, $aid, 1);
ok('edit keeps status and updates text', supportArticleGet($aid)['status'] === 'published' && supportArticleGet($aid)['title_en'] === 'Payroll how-to (edited)');
supportArticleSetStatus($aid, 'archived', 1);
ok('archived = soft delete (row still there)', supportArticleGet($aid)['status'] === 'archived');
ok('default list hides archived', !in_array($aid, array_column(supportArticleList(), 'id')));
ok('status=archived list shows it', in_array($aid, array_column(supportArticleList(['status' => 'archived']), 'id')));
ok('search with LIKE wildcards is literal', supportArticleList(['q' => '%']) === [] || !in_array($aid, array_column(supportArticleList(['q' => '%_']), 'id')));
$art = supportArticleGet($aid);
ok('payroll article hidden from Smart POS admin (module off)', !supportArticleVisible($art, $pa));
ok('payroll article visible to Smart BMS admin', supportArticleVisible($art, $ba));
ok('payroll article hidden from Smart BMS staff (role)', !supportArticleVisible($art, $bs));
ok('untagged article visible to everyone', supportArticleVisible(['page_keys' => []], $ps));
ok('category hide/show', supportCategorySetActive($catId, false) && (int)$c->query("SELECT is_active FROM support_categories WHERE id=$catId")->fetchColumn() === 0);
ok('hide on missing category returns false', supportCategorySetActive(99999999, true) === false);
$loc = supportArticleLocalized(['title_en' => 'T', 'title_sw' => '', 'body_en' => 'B', 'body_sw' => 'BS', 'simple_body_en' => 'S', 'simple_body_sw' => ''], 'sw', true);
ok('Swahili + Simple: falls back to English simple steps, English title', $loc['title'] === 'T' && $loc['body'] === 'S');

// ─────────────────────────────────────────────────────────────────────────────
section('9. Superadmin actions — guards + behaviour');
$good = ['action' => 'save_article', 'title_en' => 'Endpoint article', 'body_en' => '1. Open {nav:pos}.', 'page_keys' => ['pos']];
$r = endpoint(ART, $good, ['auth' => true]);
ok('POSITIVE: save_article creates a draft', ($r['json']['success'] ?? false) === true && !empty($r['json']['id']), substr($r['raw'], 0, 300));
$newId = (int)($r['json']['id'] ?? 0);
ok('  ...it is a draft', ($newId && supportArticleGet($newId)['status'] === 'draft'));
$r = endpoint(ART, ['id' => $newId, 'publish' => '1'] + $good, ['auth' => true]);
ok('save & publish', ($r['json']['success'] ?? false) && supportArticleGet($newId)['status'] === 'published');
ok('refuses without session', refused(endpoint(ART, $good), 'session has ended'));
ok('refuses GET', refused(endpoint(ART, $good, ['method' => 'GET', 'auth' => true]), 'Method not allowed'));
ok('refuses wrong CSRF', refused(endpoint(ART, $good + ['_csrf' => 'nope'], ['auth' => true])));
ok('refuses on a tenant host', refused(endpoint(ART, $good, ['host' => 'sometenant.' . $BASE, 'auth' => true])));
ok('refuses unknown action', refused(endpoint(ART, ['action' => 'drop'] + $good, ['auth' => true]), 'Unknown action'));
ok('refuses invalid article with a clear message', refused(endpoint(ART, ['title_en' => ''] + $good, ['auth' => true]), 'English title'));
ok('refuses saving onto a missing id', refused(endpoint(ART, ['id' => 99999999] + $good, ['auth' => true]), 'no longer exists'));
ok('set_status: archive', (endpoint(ART, ['action' => 'set_status', 'id' => $newId, 'status' => 'archived'], ['auth' => true])['json']['success'] ?? false) && supportArticleGet($newId)['status'] === 'archived');
ok('set_status: bad status refused', refused(endpoint(ART, ['action' => 'set_status', 'id' => $newId, 'status' => 'gone'], ['auth' => true]), 'Unknown status'));
ok('set_status: missing id refused', refused(endpoint(ART, ['action' => 'set_status', 'id' => 99999999, 'status' => 'draft'], ['auth' => true]), 'no longer exists'));
$r = endpoint(ART, ['action' => 'save_category', 'name_en' => 'From endpoint', 'icon' => 'shop', 'sort_order' => '3'], ['auth' => true]);
ok('save_category', ($r['json']['success'] ?? false) === true, $r['raw']);
ok('save_category: bad icon refused', refused(endpoint(ART, ['action' => 'save_category', 'name_en' => 'X1', 'icon' => '"><x', 'sort_order' => '1'], ['auth' => true]), 'icon'));
ok('set_category_active', (endpoint(ART, ['action' => 'set_category_active', 'id' => (int)($r['json']['id'] ?? 0), 'active' => '0'], ['auth' => true])['json']['success'] ?? false));
$pv = endpoint(ART, ['action' => 'preview', 'title_en' => 'Add a {shop}', 'body_en' => "1. Open {nav:warehouses}.\n<script>alert(1)</script>", 'page_keys' => ['warehouses'], 'product' => 'pos', 'role' => 'admin', 'lang' => 'en'], ['auth' => true]);
ok('preview renders Smart POS wording', str_contains($pv['json']['body_html'] ?? '', 'Shop › Shops') && ($pv['json']['title_html'] ?? '') === 'Add a shop', $pv['raw']);
ok('preview escapes HTML', !str_contains($pv['raw'], '<script>') && str_contains($pv['json']['body_html'] ?? '', '&lt;script&gt;'));
$pv2 = endpoint(ART, ['action' => 'preview', 'title_en' => 'Payroll', 'body_en' => '1. Open {nav:payroll}.', 'page_keys' => ['payroll'], 'product' => 'pos', 'role' => 'admin'], ['auth' => true]);
ok('preview reports "not visible" for a Smart POS company', ($pv2['json']['visible'] ?? null) === false);
ok('preview refused without session', refused(endpoint(ART, ['action' => 'preview', 'body_en' => 'x'])));

// ─────────────────────────────────────────────────────────────────────────────
section('10. Pages render through the real router');
$xss = supportArticleSave(supportValidateArticle(['title_en' => 'T <script>alert(9)</script>', 'body_en' => '1. "><img src=x onerror=alert(9)>', 'page_keys' => []])['values'], null, 1);
$list = route('/support?tab=articles&status=all');
ok('Articles tab renders', str_contains($list, 'New article') && !str_contains($list, 'Fatal error'), substr($list, 0, 200));
ok('Articles tab escapes titles', !str_contains($list, '<script>alert(9)') && str_contains($list, '&lt;script&gt;alert(9)'));
ok('Articles tab shows stat cards', str_contains($list, 'Drafts to review'));
$cats = route('/support?tab=categories');
ok('Categories tab renders with the add button', str_contains($cats, 'Add category') && str_contains($cats, 'catModal'));
$set = route('/support?tab=settings');
ok('Settings tab still renders the Phase 1 form', str_contains($set, 'supportForm') && str_contains($set, 'First-reply targets'));
$ed = route('/support/article?id=' . $xss);
ok('editor renders', str_contains($ed, 'Preview — as a company sees it') && !str_contains($ed, 'Fatal error'), substr($ed, 0, 200));
ok('editor escapes stored values', !str_contains($ed, '<script>alert(9)') && !str_contains($ed, '<img src=x onerror'));
ok('editor offers the page picker from the menu mirror', str_contains($ed, 'value="warehouses"') && str_contains($ed, 'Warehouses / Shops'));
$missingEd = route('/support/article?id=99999999');
ok('editor on a missing id shows a notice, not a crash', str_contains($missingEd, 'does not exist') && !str_contains($missingEd, 'Fatal error'));
ok('route map has support/article', isset(superadminRouteMap()['support/article']));
ok('legacy path redirects to the short URL', superadminShortUrlFor('app/superadmin/support_article.php') === '/support/article');

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
