<?php
/**
 * tests/test_support_center_phase3_cli.php — Help & Support Center, Phase 3
 * (support_center_plan.md): Help open to every signed-in user, the ? Help panel in the
 * header, api/support_help.php, and the rebuilt Help & Support page.
 *
 *   php tests/test_support_center_phase3_cli.php
 *
 * CLI ONLY. Snapshots support_articles / support_categories / support_search_log and
 * restores them afterwards.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$root = dirname(__DIR__);

// ── Workers: run a real API file / page with a real session ─────────────────
if (($argv[1] ?? '') === 'worker') {
    $cfg = json_decode(file_get_contents($argv[3]), true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    $_SERVER['REQUEST_METHOD'] = $cfg['method'] ?? 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost';
    if (isset($cfg['csrf_header'])) $_SERVER['HTTP_X_CSRF_TOKEN'] = $cfg['csrf_header'];
    require_once "$root/roots.php";
    $_POST = $cfg['post'] ?? []; $_GET = $cfg['get'] ?? [];
    require "$root/{$argv[2]}";
    exit;
}
if (($argv[1] ?? '') === 'page') {
    $cfg = json_decode(file_get_contents($argv[2]), true);
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (($cfg['session'] ?? []) as $k => $v) $_SESSION[$k] = $v;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    require_once "$root/roots.php";
    // Build the URL inside this process so it matches this process's getUrl() base path.
    $_GET = $cfg['get'] ?? [];
    $_SERVER['REQUEST_URI'] = getUrl('help') . ($_GET ? '?' . http_build_query($_GET) : '');
    require "$root/app/constant/settings/help.php";
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/support_articles.php";
global $pdo;
$c = getControlPdo();

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function call(string $file, array $post, array $session, string $method = 'POST', ?string $csrfHeader = null): array {
    $cfg = ['session' => $session, 'method' => $method, 'post' => $post];
    if ($csrfHeader !== null) $cfg['csrf_header'] = $csrfHeader;
    $f = tempnam(sys_get_temp_dir(), 'sup'); file_put_contents($f, json_encode($cfg));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($file) . ' ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    $s = strpos($o, '{');
    $j = $s === false ? null : json_decode(substr($o, $s), true);
    return ['raw' => $o, 'json' => is_array($j) ? $j : null];
}
function page(array $session, string $uri, array $get = []): string {
    $f = tempnam(sys_get_temp_dir(), 'sup'); file_put_contents($f, json_encode(['session' => $session, 'uri' => $uri, 'get' => $get]));
    $o = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' page ' . escapeshellarg($f) . ' 2>&1');
    @unlink($f);
    return $o;
}
function noErr(string $h): bool { foreach (['Fatal error', 'Parse error', 'Uncaught', 'Warning:', 'Notice:', 'Deprecated:', 'SQLSTATE'] as $e) if (stripos($h, $e) !== false) return false; return true; }

echo "\nBMS — Help & Support Center, Phase 3\n";

// Snapshot + restore.
$snaps = [];
foreach (['support_articles', 'support_categories', 'support_search_log'] as $t) $snaps[$t] = $c->query("SELECT * FROM $t")->fetchAll(PDO::FETCH_ASSOC);
register_shutdown_function(function () use ($c, $snaps) {
    foreach ($snaps as $t => $rows) {
        $c->exec("DELETE FROM $t");
        foreach ($rows as $r) {
            $cols = array_keys($r);
            $c->prepare("INSERT INTO $t (`" . implode('`,`', $cols) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")->execute(array_values($r));
        }
    }
});

$adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();
$TOKEN = bin2hex(random_bytes(16));
$ADMIN = ['user_id' => $adminUid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1, 'role' => 'admin', 'csrf_token' => $TOKEN];
// A real, temporary non-admin user (the header loads the signed-in user's row).
// Its own role with NO permissions, so every denial below is proven against a truly restricted user.
$pdo->exec("DELETE FROM users WHERE username = '__help_staff'");
$pdo->exec("DELETE FROM roles WHERE role_name = '__help_test_role'");
$pdo->exec("INSERT INTO roles (role_name, is_admin, description, created_at, updated_at) VALUES ('__help_test_role', 0, 'phase 3 test', NOW(), NOW())");
$staffRole = (int)$pdo->lastInsertId();
register_shutdown_function(function () use ($pdo, $staffRole) { $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$staffRole]); $pdo->prepare('DELETE FROM roles WHERE role_id = ?')->execute([$staffRole]); });
$pdo->prepare("INSERT INTO users (username, email, first_name, last_name, role_id, is_active, password, created_at) VALUES ('__help_staff', 'helpstaff@example.test', 'Help', 'Staff', ?, 1, 'x', NOW())")->execute([$staffRole]);
$staffUid = (int)$pdo->lastInsertId();
register_shutdown_function(function () use ($pdo, $staffUid) { $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$staffUid]); });
$STAFF = ['user_id' => $staffUid, 'username' => '__help_staff', 'is_admin' => false, 'role_id' => $staffRole, 'role' => 'Staff', 'csrf_token' => $TOKEN,
          'permissions' => ['pos' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false, 'review' => false, 'approve' => false]],
          'scope' => ['is_admin' => false, 'projects' => [], 'warehouses' => [], 'suppliers' => [], 'customers' => [], 'employees' => [], 'computed_at' => time()]];

// ─────────────────────────────────────────────────────────────────────────────
section('1. Lint');
foreach (['core/permissions.php', 'core/help_nav.php', 'core/support_articles.php', 'includes/help_panel.php', 'api/support_help.php',
          'app/constant/settings/help.php', 'header.php', 'lang/sw.php', 'migrations/tenant/2026_10_24_help_self_service.php'] as $f) {
    $o = []; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok("$f lint-clean", $rc === 0, implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. Help is open to every signed-in user, nothing else changes');
$perm = file_get_contents("$root/core/permissions.php");
ok("canView special-case is only for 'help' and only when signed in", str_contains($perm, "\$pageKey === 'help' && !empty(\$_SESSION['user_id'])"));
$entPos = strpos($perm, 'tenantModuleAllowsPage($pageKey)'); $helpPos = strpos($perm, "\$pageKey === 'help'");
ok('module entitlement is still checked first', $entPos !== false && $helpPos !== false && $entPos < $helpPos);
$_SESSION = ['user_id' => 999952, 'role_id' => 999, 'is_admin' => false, 'permissions' => []];
ok('non-admin with no permissions can view help', canView('help') === true);
ok('...but still not other pages (payroll)', canView('payroll') === false);
ok('...and not create on help', canCreate('help') === false);
$_SESSION = [];
ok('signed-out: help is NOT viewable', canView('help') === false);
$seed = file_get_contents("$root/schema/tenant_seed_defaults.sql");
ok('new-tenant seed hides the help switch in the roles matrix', (bool)preg_match("/\\(157,'','help','Help'[^;]*,1\\);/", $seed));
$mig = file_get_contents("$root/migrations/tenant/2026_10_24_help_self_service.php");
ok('tenant migration guarded and idempotent (only rows still visible)', str_contains($mig, "COALESCE(is_hidden, 0) = 0") && str_contains($mig, "PHP_SAPI !== 'cli'"));
ok('local DB: help row hidden after the migration', (int)$pdo->query("SELECT is_hidden FROM permissions WHERE page_key='help'")->fetchColumn() === 1);

// ─────────────────────────────────────────────────────────────────────────────
section('3. Current page detection and "Take me there" links');
foreach (['warehouses', 'pos', 'help', 'expenses', 'customers', 'invoices'] as $k) {
    $u = (string)getUrl($k);
    ok("request to getUrl('$k') → $k", helpCurrentPageKey($u) === $k, var_export(helpCurrentPageKey($u), true) . " for $u");
}
ok('the Receive Stock page → restock', helpCurrentPageKey(getUrl('receive_stock')) === 'restock');
ok('sub-path → its page', helpCurrentPageKey(rtrim(getUrl('warehouses'), '/') . '/5') === 'warehouses');
ok('unknown path → null', helpCurrentPageKey('/no/such/page-xyz') === null);
ok('helpNavUrl(restock) opens the Receive Stock page', str_ends_with((string)helpNavUrl('restock'), 'receive_stock'));
ok('my_settings is permission-gated in the mirror (link shows, page may not open)', !helpKeyAllowed('my_settings', ['can' => fn() => false] + helpPreviewContext('pos', 'staff')));

// ─────────────────────────────────────────────────────────────────────────────
section('4. Tenant data — only published, visible, shown-category articles');
$c->exec('DELETE FROM support_articles'); $c->exec('DELETE FROM support_categories'); $c->exec('DELETE FROM support_search_log');
supportSeedDefaults($c, controlDbName());
unset($GLOBALS['__support_published']);
$pa = helpPreviewContext('pos', 'admin'); $ba = helpPreviewContext('bms', 'admin'); $ps = helpPreviewContext('pos', 'staff');
ok('drafts only: tenants see nothing yet', supportTenantList($pa, 'en') === []);
$c->exec("UPDATE support_articles SET status='published'");
unset($GLOBALS['__support_published']);
$posList = supportTenantList($pa, 'en');
$titles = array_column($posList, 'title');
ok('published starter articles now visible to a Smart POS admin', count($posList) >= 14, count($posList) . ' visible');
ok('Smart POS wording in titles ("Add a new shop")', in_array('Add a new shop', $titles, true));
ok('Smart BMS wording for the same article ("Add a new warehouse")', in_array('Add a new warehouse', array_column(supportTenantList($ba, 'en'), 'title'), true));
ok('category names resolved too ({Shops} → Shops)', in_array('Shops', array_column($posList, 'category'), true));
ok('Smart BMS does not see the Smart POS-only profit report article', !in_array('See how much profit you made', array_column(supportTenantList($ba, 'en'), 'title'), true));
$staffTitles = array_column(supportTenantList($ps, 'en'), 'title');
ok('Smart POS staff do not see expense or user articles', !in_array('Record a business expense', $staffTitles, true) && !in_array('Add a user and choose their role', $staffTitles, true));
ok('Smart POS staff still see selling help', in_array('Sell items and give a receipt', $staffTitles, true));
$someId = (int)$c->query("SELECT id FROM support_articles WHERE title_en = 'Add a new {shop}'")->fetchColumn();
$c->prepare("UPDATE support_articles SET status='archived' WHERE id=?")->execute([$someId]);
unset($GLOBALS['__support_published']);
ok('archived article disappears', !in_array('Add a new shop', array_column(supportTenantList($pa, 'en'), 'title'), true));
ok('archived article cannot be opened by id', supportTenantArticle($someId, $pa, 'en') === null);
$c->prepare("UPDATE support_articles SET status='published' WHERE id=?")->execute([$someId]);
$catShops = (int)$c->query("SELECT id FROM support_categories WHERE name_en='{Shops}'")->fetchColumn();
$c->prepare("UPDATE support_categories SET is_active=0 WHERE id=?")->execute([$catShops]);
unset($GLOBALS['__support_published']);
ok('hiding a category hides its articles', !in_array('Add a new shop', array_column(supportTenantList($pa, 'en'), 'title'), true));
$c->prepare("UPDATE support_categories SET is_active=1 WHERE id=?")->execute([$catShops]);
unset($GLOBALS['__support_published']);

section('5. Search — synonyms, wording note, safety');
$r = array_column(supportTenantSearch('warehouse', $pa, 'en'), 'title');
ok('Smart POS: "warehouse" finds shop articles', in_array('Add a new shop', $r, true), implode(' | ', $r));
ok('Smart POS: Swahili "duka" finds shop articles', in_array('Add a new shop', array_column(supportTenantSearch('duka', $pa, 'en'), 'title'), true));
ok('"refund" finds the returns article', in_array('Return an item a customer brought back', array_column(supportTenantSearch('refund', $pa, 'en'), 'title'), true));
ok('note: Smart POS reader used "warehouse"', supportSearchNote('create warehouse', $pa) === 'In this system warehouses are called shops.');
ok('note: Smart BMS reader used "shop"', supportSearchNote('new shop', $ba) === 'In this system shops are managed as warehouses.');
ok('no note when the reader already uses the company wording', supportSearchNote('new shop', $pa) === '');
ok('Smart POS: "payroll" finds nothing (module off)', supportTenantSearch('payroll', $pa, 'en') === []);
ok('stop-words only → no results, no error', supportTenantSearch('how do i', $pa, 'en') === []);
ok('SQL-ish / script input is just text', supportTenantSearch("' OR 1=1 -- <script>", $pa, 'en') === []);
ok('very long query is cut, not an error', is_array(supportTenantSearch(str_repeat('shop ', 500), $pa, 'en')));

section('6. Article render, Take me there, counters');
$a = supportTenantArticle($someId, $pa, 'en', 'dashboard');
ok('article renders in Smart POS words', $a && $a['title'] === 'Add a new shop' && str_contains($a['html'], 'Shop › Shops'));
ok('"Take me there" points at the shop page with its menu path', $a && $a['take'] && $a['take']['label'] === 'Shop › Shops' && $a['take']['url'] === helpNavUrl('warehouses'));
ok('no "Take me there" when already on that page', supportTenantArticle($someId, $pa, 'en', 'warehouses')['take'] === null);
$_SESSION = [];
$v0 = (int)$c->query("SELECT views FROM support_articles WHERE id=$someId")->fetchColumn();
supportArticleCountView($someId); supportArticleCountView($someId); supportArticleCountView($someId);
ok('a view counts once per session', (int)$c->query("SELECT views FROM support_articles WHERE id=$someId")->fetchColumn() === $v0 + 1);
ok('first vote counts', supportArticleVote($someId, true) === true);
ok('second vote in the same session is ignored', supportArticleVote($someId, false) === false);
ok('only one helpful_yes recorded, no helpful_no', (int)$c->query("SELECT helpful_yes FROM support_articles WHERE id=$someId")->fetchColumn() === 1 && (int)$c->query("SELECT helpful_no FROM support_articles WHERE id=$someId")->fetchColumn() === 0);
ok('search logged once', supportLogSearch('Make A  Credit sale', 3) === true && supportLogSearch('make a credit sale', 3) === false);
ok('search log stores the normalised query and count', (int)$c->query("SELECT COUNT(*) FROM support_search_log WHERE query='make a credit sale' AND results_count=3")->fetchColumn() === 1);
ok('one-letter queries are not logged', supportLogSearch('a', 0) === false);

// ─────────────────────────────────────────────────────────────────────────────
section('7. api/support_help.php — guards');
$api = 'api/support_help.php';
$r = call($api, ['action' => 'home', '_csrf' => $TOKEN], []);
ok('signed-out → 401 Unauthorized', str_contains($r['raw'], 'Unauthorized') && !str_contains($r['raw'], '"success":true'));
$r = call($api, ['action' => 'home', '_csrf' => $TOKEN], $ADMIN, 'GET');
ok('GET refused', str_contains($r['raw'], 'Method not allowed'));
$r = call($api, ['action' => 'home', '_csrf' => 'wrong'], $ADMIN);
ok('wrong CSRF refused', str_contains($r['raw'], 'CSRF') && !str_contains($r['raw'], '"success":true'));
$r = call($api, ['action' => 'home'], $ADMIN, 'POST', $TOKEN);
ok('CSRF via the X-CSRF-Token header (what the panel sends) accepted', ($r['json']['success'] ?? false) === true, substr($r['raw'], 0, 200));
$r = call($api, ['action' => 'nope', '_csrf' => $TOKEN], $ADMIN);
ok('unknown action → 400', str_contains($r['raw'], 'Unknown action'));

section('8. api/support_help.php — behaviour');
$r = call($api, ['action' => 'home', 'page_key' => 'pos', '_csrf' => $TOKEN], $ADMIN);
$here = array_column($r['json']['here'] ?? [], 'title');
ok('home on POS: selling help first', in_array('Sell items and give a receipt', $here, true), substr($r['raw'], 0, 300));
ok('home returns the page path', ($r['json']['page'] ?? '') !== '');
ok('home with an unknown page key is safe', (call($api, ['action' => 'home', 'page_key' => '../../etc', '_csrf' => $TOKEN], $ADMIN)['json']['success'] ?? false) === true);
$r = call($api, ['action' => 'search', 'q' => 'customer', '_csrf' => $TOKEN], $ADMIN);
ok('search returns results', count($r['json']['results'] ?? []) > 0);
$r = call($api, ['action' => 'article', 'id' => $someId, 'page_key' => 'dashboard', '_csrf' => $TOKEN], $ADMIN);
ok('article returns rendered html + take', !empty($r['json']['article']['html']) && isset($r['json']['article']['take']));
ok('article payload has no raw body/placeholders', !str_contains($r['raw'], '{nav:') && !str_contains($r['raw'], 'body_en'));
$r = call($api, ['action' => 'article', 'id' => 99999999, '_csrf' => $TOKEN], $ADMIN);
ok('missing article → not available', str_contains($r['raw'], 'not available'));
$payrollId = supportArticleSave(supportValidateArticle(['title_en' => 'Payroll secret steps', 'body_en' => '1. Open {nav:payroll}.', 'page_keys' => ['payroll']])['values'], null, 1, 'published');
$r = call($api, ['action' => 'article', 'id' => $payrollId, '_csrf' => $TOKEN], $STAFF);
ok('staff cannot open an article for a page they cannot see (by id)', str_contains($r['raw'], 'not available') && !str_contains($r['raw'], 'secret'));
$r = call($api, ['action' => 'search', 'q' => 'payroll secret', '_csrf' => $TOKEN], $STAFF);
ok('...nor find it by search', ($r['json']['results'] ?? null) === []);
$r = call($api, ['action' => 'home', '_csrf' => $TOKEN], $STAFF);
ok('staff (no permissions) can still use the panel', ($r['json']['success'] ?? false) === true);
$r = call($api, ['action' => 'feedback', 'id' => $someId, 'helpful' => '1', '_csrf' => $TOKEN], $ADMIN);
ok('feedback accepted and logged', ($r['json']['success'] ?? false) === true && (int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='help_feedback' AND description LIKE 'Help article #$someId%'")->fetchColumn() >= 1);
$r = call($api, ['action' => 'feedback', 'id' => $payrollId, 'helpful' => '1', '_csrf' => $TOKEN], $STAFF);
ok('feedback on a hidden article refused', str_contains($r['raw'], 'not available'));
$xssId = supportArticleSave(supportValidateArticle(['title_en' => 'T <img src=x onerror=alert(7)>', 'body_en' => "1. <script>alert(7)</script>\nTip: <b onclick=x>y</b>", 'page_keys' => []])['values'], null, 1, 'published');
$r = call($api, ['action' => 'article', 'id' => $xssId, '_csrf' => $TOKEN], $ADMIN);
$html = $r['json']['article']['html'] ?? '';
ok('article body escaped (no live tags from content)', !preg_match('/<(script|img|b)\b/i', $html) && str_contains($html, '&lt;script&gt;'), $html);
ok('title is plain text for the client to insert as text', ($r['json']['article']['title'] ?? '') === 'T <img src=x onerror=alert(7)>');

// ─────────────────────────────────────────────────────────────────────────────
section('9. Header + Help & Support page render');
$h = page($ADMIN, (string)getUrl('help'));
ok('help page renders with no PHP error', noErr($h), substr($h, 0, 300));
ok('panel rendered once', substr_count($h, 'id="bmsHelpPanel"') === 1);
ok('desktop ? button rendered', str_contains($h, 'bms-help-btn d-inline-flex') && str_contains($h, 'data-bs-target="#bmsHelpPanel"'));
ok('a phone ? button rendered too (round)', str_contains($h, 'bms-help-btn-round'));
ok('panel knows the current page (help)', str_contains($h, 'data-page-key="help"'));
ok('topics shown when articles are published', str_contains($h, 'help-topic'));
ok('XSS title escaped in the page list', !str_contains($h, '<img src=x onerror=alert(7)>') && str_contains($h, '&lt;img src=x onerror=alert(7)&gt;'));
preg_match_all('~<script>(.*?)</script>~s', $h, $sm);
$panelJs = '';
foreach ($sm[1] as $js) if (str_contains($js, 'bmsHelpPanel')) $panelJs = $js;
$tmp = tempnam(sys_get_temp_dir(), 'js') . '.js'; file_put_contents($tmp, $panelJs);
$o = []; exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc); @unlink($tmp);
ok('panel script is valid JavaScript', $panelJs !== '' && $rc === 0, implode(' ', $o));
ok('panel inserts titles as text; the only innerHTML from data is the escaped article body', str_contains($panelJs, 'textContent') && substr_count($panelJs, 'innerHTML') - substr_count($panelJs, 'btn.innerHTML') === 1 && str_contains($panelJs, 'body.innerHTML = a.html'));
$hs = page($ADMIN, (string)getUrl('help') . '?q=warehouse', ['q' => 'warehouse']);
ok('page search renders', noErr($hs) && str_contains($hs, 'results'));
$ha = page($ADMIN, (string)getUrl('help') . '?article=' . $xssId, ['article' => (string)$xssId]);
ok('page article view renders escaped', noErr($ha) && !str_contains($ha, '<script>alert(7)') && str_contains($ha, '&lt;script&gt;alert(7)'));
$hp = page($STAFF, (string)getUrl('help') . '?article=' . $payrollId, ['article' => (string)$payrollId]);
ok('staff opening a hidden article by URL sees "not available"', noErr($hp) && str_contains($hp, 'not available') && !str_contains($hp, 'Payroll secret'), substr($hp, 0, 200));
ok('staff page has no BJP contact card', !str_contains($hp, 'Need More Help?'));
$c->exec("UPDATE support_articles SET status='draft'");
unset($GLOBALS['__support_published']);
$he = page($STAFF, (string)getUrl('help'));
ok('empty state when nothing is published', noErr($he) && str_contains($he, 'being prepared'));

echo "\n--- $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
