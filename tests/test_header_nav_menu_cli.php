<?php
/**
 * Header top-nav — "show only what the role can open" — CLI suite
 *   php tests/test_header_nav_menu_cli.php
 *
 * core/nav_menu.php::bmsNavMenu() renders every header group (Core, Finance,
 * CRM, Sales, Inventory, Procurement, Operations, Comms, Docs, Reports):
 * 0 granted links = hidden, 1 = that page as a direct header link, 2+ = the
 * group dropdown. Simple POS mode keeps each group's original gate & markup.
 *
 *   A. STATIC  — touched files lint clean.
 *   B. UNIT    — bmsNavMenu()/bmsNavMenuLinks()/bmsNavMenuStripEmptyHeaders()
 *                on fixture menus (0/1/2+ links, companions, empty headers).
 *   C. LIVE    — the real header.php rendered for temporary roles (all
 *                modules on): the reported Staff role (Invoices + Quotations +
 *                Customer Visits) gets three direct links and no empty menus;
 *                Quotations + Sales Orders + Invoices -> "Sales" dropdown; a
 *                POS-only role keeps its direct POS link.
 *   D. NO REGRESSION — admin with every module on renders the same nav as the
 *                pre-change header (origin/develop), and Simple POS mode
 *                renders the same nav as before for admin AND a limited role.
 *
 * Every live render runs in its own process inside a DB transaction that is
 * rolled back (temp role/user/settings never persist). Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
$root = dirname(__DIR__);
$pdo  = null;
// Sections A/B need no database; the live sections C/D run only where a config exists (not in CI).
if (is_file("$root/includes/config.php")) {
    try { require_once "$root/roots.php"; } catch (Throwable $e) { $pdo = null; }
}
if (!function_exists('get_setting')) {
    function get_setting($key, $default = '') { return $default; }
}
require_once "$root/core/pos_nav.php";
require_once "$root/core/nav_menu.php";
$live = $pdo instanceof PDO;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

/**
 * Render the nav <ul> of $headerFile in a fresh process for a temp user.
 * $roleId 1 = admin; otherwise a temp role is created with $grants (page_keys).
 */
function renderNav(string $headerFile, array $grants, bool $admin, bool $simple, array $featureOverrides = []): string
{
    global $root;
    $tpl = <<<'PHP'
error_reporting(0);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_URI'] = '/dashboard';
// helpers.php's get_setting() is function_exists-guarded and caches every
// setting statically on first call (during roots.php), so a DB write here
// would never be seen. Pre-define it: same cached DB read, with
// pos_simple_mode forced for this render — nothing is written.
function get_setting($key, $default = '') {
    global $pdo;
    static $cache = null;
    if ($key === 'pos_simple_mode') return __SIMPLE__;
    if ($cache === null) {
        $cache = [];
        if ($pdo instanceof PDO) {
            try { foreach ($pdo->query("SELECT setting_key, setting_value FROM system_settings") as $r) $cache[$r['setting_key']] = $r['setting_value']; } catch (Exception $e) {}
        }
    }
    return $cache[$key] ?? $default;
}
require '__ROOT__/roots.php';
global $pdo;
$GLOBALS['__bms_features'] = array_merge(array_fill_keys(allFeatureKeys(), true), __FEATOVR__);   // "all modules on" (+ per-case overrides)
$pdo->beginTransaction();
try {
    if (__ADMIN__) {
        $rid = 1;
    } else {
        $pdo->prepare("INSERT INTO roles (role_name, description, created_at) VALUES (?, 'navtest', NOW())")->execute(['ZZTEST nav ' . uniqid()]);
        $rid = (int)$pdo->lastInsertId();
        $g = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view) SELECT ?, permission_id, 1 FROM permissions WHERE page_key = ?");
        foreach (__GRANTS__ as $k) $g->execute([$rid, $k]);
    }
    $pdo->prepare("INSERT INTO users (username, email, first_name, is_admin, is_active, role_id) VALUES (?, ?, 'Nav', ?, 1, ?)")
        ->execute(['zznav' . uniqid(), 'zznav' . uniqid() . '@test.local', __ADMIN__ ? 1 : 0, $rid]);
    $uid = (int)$pdo->lastInsertId();
    $_SESSION['user_id'] = $uid; $_SESSION['role_id'] = $rid; $_SESSION['is_admin'] = (bool)__ADMIN__;
    ob_start();
    include '__HEADER__';
    $html = ob_get_clean();
    while (ob_get_level() > 0) ob_end_clean();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
echo preg_match('/<ul class="navbar-nav me-auto">(.*?)<!-- User Account/s', $html, $m) ? $m[1] : 'NO_NAV';
PHP;
    $code = str_replace(
        ['__ROOT__', '__HEADER__', '__SIMPLE__', '__ADMIN__', '__GRANTS__', '__FEATOVR__'],
        [addslashes($root), addslashes($headerFile), var_export($simple ? '1' : '0', true), $admin ? 'true' : 'false', var_export($grants, true), var_export($featureOverrides, true)],
        $tpl
    );
    $tmp = tempnam(sys_get_temp_dir(), 'navtest_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = (string)shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return $out;
}

/** Comparable form of nav HTML: no comments, no companion markers, no layout whitespace. */
function normNav(string $html): string
{
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = str_replace(' data-nav-companion', '', $html);
    $html = preg_replace('/\s+/', ' ', $html);
    return trim(preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html));
}

/** Direct (non-dropdown) header links: label => href. */
function directLinks(string $nav): array
{
    $out = [];
    preg_match_all('/<li class="nav-item">\s*<a class="nav-link" href="([^"]*)">(.*?)<\/a>/s', $nav, $m, PREG_SET_ORDER);
    foreach ($m as $a) $out[trim(strip_tags($a[2]))] = $a[1];
    return $out;
}

// ── A ──
section('A. Static');
foreach (['header.php', 'core/nav_menu.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    ok($rc === 0, "$f lints clean");
}

// ── B ──
section('B. Unit — bmsNavMenu() on fixture menus');
if (posSimpleModeEnabled()) {
    ok(true, "local Simple POS mode is on — unit cases (non-simple path) skipped; section D covers Simple mode");
} else {
    $ul = fn(string $body) => '<ul class="dropdown-menu" aria-labelledby="x">' . $body . '</ul>';
    $h  = fn(string $t) => '<li><h6 class="dropdown-header">' . $t . '</h6></li>';
    $a  = fn(string $href, string $t, string $extra = '') => '<li><a class="dropdown-item"' . $extra . ' href="' . $href . '"><i class="bi bi-x"></i> ' . $t . '</a></li>';

    ob_start(); $r = bmsNavMenu($ul($h('Sec') . $h('Other')), 'x', 'Group', true); $o = ob_get_clean();
    ok($r === false && $o === '', '0 links -> nothing rendered (even when the legacy gate is true)');

    ob_start(); $r = bmsNavMenu($ul($h('Sec') . $a('/quotations', 'Quotations') . $h('Returns')), 'x', 'Sales', false); $o = ob_get_clean();
    ok($r === true && $o === '<li class="nav-item"><a class="nav-link" href="/quotations"><i class="bi bi-x"></i> Quotations</a></li>',
        '1 link -> direct header link with the item\'s own icon + label (legacy gate ignored)');

    ob_start(); bmsNavMenu($ul($a('/invoices', 'Invoices') . $a('/receive_payment', 'Receive Payment', ' data-nav-companion')), 'x', 'Finance', false); $o = ob_get_clean();
    ok(strpos($o, 'href="/invoices"') !== false && strpos($o, 'dropdown-toggle') === false && strpos($o, 'receive_payment') === false,
        '1 link + 1 companion -> still a single direct link (companion not counted)');

    ob_start(); bmsNavMenu($ul($h('A') . $a('/q', 'Q') . $h('Empty') . $h('B') . $a('/s', 'S') . $h('Tail')), 'salesDropdown', 'Sales', false); $o = ob_get_clean();
    ok(strpos($o, 'id="salesDropdown"') !== false && strpos($o, 'href="/q"') !== false && strpos($o, 'href="/s"') !== false,
        '2 links -> dropdown keeping both links');
    ok(strpos($o, '>A<') !== false && strpos($o, '>B<') !== false && strpos($o, 'Empty') === false && strpos($o, 'Tail') === false,
        '2 links -> headers with links kept, empty middle/trailing headers dropped (no greedy cross-section match)');

    $mega = '<div class="dropdown-menu mega-dropdown-menu"><div class="row">'
          . '<div class="col-lg-3 mega-column"><h6>Fin</h6><a class="dropdown-item" href="/tb">TB</a></div>'
          . '<div class="col-lg-3 mega-column"><h6>Empty</h6></div>'
          . '<div class="col-lg-3 mega-column"><h6>Biz</h6><a class="dropdown-item" href="/sr">SR</a></div></div></div>';
    ob_start(); bmsNavMenu($mega, 'reportsDropdown', 'Reports', false, 'nav-item mega-dropdown'); $o = ob_get_clean();
    ok(strpos($o, 'nav-item mega-dropdown') !== false && strpos($o, 'Empty') === false && strpos($o, 'href="/tb"') !== false && strpos($o, 'href="/sr"') !== false,
        'mega menu: empty column dropped, filled columns and links kept');
}

// ── C ──
section('C. Live — real header.php for temporary roles (all modules on)');
if (!$live) {
    ok(true, 'no database config (CI) — live header renders (sections C/D) skipped');
    exit;
}
$hdr = "$root/header.php";

$staff = renderNav($hdr, ['invoices', 'quotations', 'field_visits'], false, false);
$d = directLinks($staff);
ok(isset($d['Invoices']) && isset($d['Quotations']) && isset($d['Customer Visits']),
    'Staff (Invoices + Quotations + Customer Visits): all three are direct header links (got: ' . implode(', ', array_keys($d)) . ')');
ok(strpos($staff, 'dropdown-toggle') === false, 'Staff: no dropdown at all — nothing empty or irrelevant in the header');
ok(substr_count($staff, '>Invoices<') + substr_count($staff, '> Invoices<') === 1, 'Staff: Invoices appears exactly once (not duplicated via Sales)');

$sales = renderNav($hdr, ['invoices', 'quotations', 'sales_orders'], false, false);
ok(strpos($sales, 'id="salesDropdown"') !== false, 'Quotations + Sales Orders + Invoices: "Sales" is a dropdown');
ok(isset(directLinks($sales)['Invoices']), '...and Invoices is a direct link from Finance (its own single item there)');

$pos = renderNav($hdr, ['pos'], false, false);
ok(isset(directLinks($pos)['POS']) || count(array_filter(array_keys(directLinks($pos)), fn($k) => in_array($k, ['POS', 'Sell'], true))) === 1,
    'POS-only role: POS stays a direct header link, as before (got: ' . implode(', ', array_keys(directLinks($pos))) . ')');
ok(strpos($pos, 'id="salesDropdown"') === false, 'POS-only role: no Sales dropdown');

$hr = renderNav($hdr, ['employees', 'leaves'], false, false);
ok(strpos($hr, 'id="operationsDropdown"') !== false && strpos($hr, '>Workforce<') !== false && strpos($hr, 'Assets &amp; Maintenance') === false && strpos($hr, 'Assets & Maintenance') === false,
    'Employees + Leaves: Operations dropdown with its sections, empty "Assets & Maintenance" header dropped');

// ── D ──
section('D. No regression — admin (all modules on) and Simple POS mode render as before');
$oldSrc = shell_exec('git -C ' . escapeshellarg($root) . ' show origin/develop:header.php 2>&1');
if (!is_string($oldSrc) || strpos($oldSrc, '<?php') !== 0) {
    ok(true, 'origin/develop:header.php not available — before/after comparison skipped');
} else {
    $oldHdr = "$root/header__navtest_old.php";   // same dir: header.php requires via __DIR__
    file_put_contents($oldHdr, $oldSrc);
    try {
        $cases = [
            ['admin, all modules on, normal mode', [], true, false],
            ['admin, Simple POS mode', [], true, true],
            ['limited role (pos, quotations, invoices, expenses, products), Simple POS mode', ['pos', 'quotations', 'invoices', 'expenses', 'products'], false, true],
        ];
        // Restaurant POS off (the default for every tenant) — the nav must be byte-identical.
        // With it on, restaurant_plan.md deliberately turns the Restaurant link into a dropdown;
        // that case is checked separately below.
        $noRestaurant = ['restaurant_pos' => false];
        foreach ($cases as [$label, $grants, $admin, $simple]) {
            $label .= ', Restaurant POS off';
            $new = normNav(renderNav($hdr, $grants, $admin, $simple, $noRestaurant));
            $old = normNav(renderNav($oldHdr, $grants, $admin, $simple, $noRestaurant));
            if ($simple) {
                ok(strpos($new, 'My Business') !== false || strpos($new, 'Biashara') !== false || $grants !== [],
                    "$label: render really ran in Simple POS mode (guard against a vacuous comparison)");
                ok(strpos($new, 'id="financeDropdown"') === false, "$label: Finance dropdown hidden, as Simple POS mode always did");
            }
            ok($new !== '' && $new !== 'NO_NAV' && $new === $old, "$label: nav identical to the pre-change header");
            if ($new !== $old) {
                $i = 0; $n = min(strlen($new), strlen($old));
                while ($i < $n && $new[$i] === $old[$i]) $i++;
                echo "      first diff @$i\n      new: " . substr($new, max(0, $i - 80), 240) . "\n      old: " . substr($old, max(0, $i - 80), 240) . "\n";
            }
        }
        // Restaurant POS on: the ONLY difference allowed is the Restaurant menu item itself.
        $cut = fn(string $h) => preg_replace('#<li class="nav-item( dropdown)?">\s*<a class="nav-link[^"]*"[^>]*(href="[^"]*/restaurant"|id="restaurantDropdown")[^>]*>.*?</li>(\s*</ul>\s*</li>)?#s', '', $h, 1);
        $newR = normNav(renderNav($hdr, [], true, true));
        $oldR = normNav(renderNav($oldHdr, [], true, true));
        ok(strpos($newR, 'id="restaurantDropdown"') !== false, 'admin, Simple POS mode, Restaurant POS on: Restaurant is a dropdown (Menu, Tables, Kitchen…)');
        ok($cut($newR) === $cut($oldR), 'admin, Simple POS mode, Restaurant POS on: everything else in the nav identical');
    } finally {
        @unlink($oldHdr);
    }
}
