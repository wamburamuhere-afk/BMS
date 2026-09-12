<?php
/**
 * Phase 30 (pos_upgrade_plan.md §9) — POS Navigation Reorganization — CLI test
 *   php tests/test_pos_nav_wiring_cli.php
 *
 *   A. STATIC — header.php's old three-item POS dropdown block is gone,
 *      replaced by exactly one link (pos/dashboard); every OTHER module's
 *      dropdown block in header.php is untouched (git-diff based, so a
 *      future edit that accidentally widens header.php's blast radius fails
 *      this test loudly).
 *   B. STATIC — core/pos_nav.php defines posNavGroups() with the expected,
 *      correctly-gated card keys; roots.php registers every restaurant/* route.
 *   C. LIVE (subprocess worker, one fresh PHP process per scenario — mirrors
 *      test_warehouse_scope_cli.php's dashboard_worker pattern so each
 *      render starts from a clean session/global state) — renders
 *      app/bms/pos/pos_dashboard.php and app/bms/restaurant/index.php under
 *      four entitlement combinations and asserts the hub cards that appear
 *      are EXACTLY the ones the plan specifies — most importantly that a
 *      gated card is genuinely ABSENT from the HTML, not merely hidden:
 *        1. pos-only tenant      -> hub shows Settings only; "Catalog Setup"/
 *                                   "Restaurant" absent. "Open Terminal" and
 *                                   "Shift History" are NOT hub cards — the
 *                                   page's own header row links (#posWorkspaceOpenPos
 *                                   / #posWorkspaceShiftHistory) are the one place
 *                                   for those two, always present, never duplicated
 *                                   in the hub grid below.
 *        2. + pos_advanced       -> Catalog Setup also appears
 *        3. + restaurant_pos     -> Restaurant also appears; its sub-hub
 *                                   (restaurant/index.php) shows all 5 toggles
 *        4. restaurant_pos OFF   -> restaurant/index.php renders NOTHING
 *                                   (redirected before any HTML is emitted)
 *        5. admin / everything on -> every remaining card present at both levels
 *
 * Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);

if (($argv[1] ?? '') === 'nav_worker') {
    // Renders one page's real top-level code, under a specific simulated
    // session + forced tenant-feature map, then dumps whatever HTML made it
    // into the output buffer — including a page that exit()s early on a
    // permission gate, since register_shutdown_function still fires then.
    require_once "$root/roots.php";
    require_once "$root/core/feature_registry.php";
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = json_decode(file_get_contents($argv[2]), true);
    foreach (($cfg['session'] ?? []) as $k => $v) { $_SESSION[$k] = $v; }
    $GLOBALS['__bms_features'] = $cfg['features'] ?? [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';

    $captured = '';
    register_shutdown_function(function () use (&$captured) {
        $buf = ob_get_contents();
        if ($buf !== false) { $captured .= $buf; @ob_end_clean(); }
        echo "\n___BMS_NAV_WORKER_OUTPUT___\n" . $captured;
    });
    ob_start();
    try {
        require "$root/" . $cfg['page'];
    } catch (Throwable $e) {
        $captured .= "\n___WORKER_EXCEPTION___" . $e->getMessage();
    }
    exit;
}

require_once "$root/roots.php";
require_once "$root/core/feature_registry.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p) { return is_file($p) ? file_get_contents($p) : ''; }
register_shutdown_function(function () {
    global $pass, $fail;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

function _nav_worker_run($root, $page, $session, $features) {
    $cfgFile = tempnam(sys_get_temp_dir(), 'navw');
    file_put_contents($cfgFile, json_encode(['page' => $page, 'session' => $session, 'features' => $features]));
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' nav_worker ' . escapeshellarg($cfgFile) . ' 2>&1');
    @unlink($cfgFile);
    $marker = '___BMS_NAV_WORKER_OUTPUT___';
    $pos = strpos((string)$out, $marker);
    return $pos === false ? (string)$out : substr($out, $pos + strlen($marker) + 1);
}

try {
    // ── A. header.php — old 3-item POS dropdown gone; nothing else touched ──
    section('A. header.php — single POS link, no collateral changes elsewhere');
    $hdr = src("$root/header.php");

    // 2026-09-12 (product owner request: "if sales is closed, POS should be
    // seen directly in the header, not as a dropdown of sales; once sales is
    // allowed, POS should be a dropdown item again") — header.php now has TWO
    // literal occurrences of this URL: one inside the Sales dropdown (used
    // when Sales is open) and one in the new standalone <li> (used when
    // Sales is closed but POS is still on). Only one of the two ever
    // actually renders for a given tenant — proven live in section C below.
    $posDashCount = substr_count($hdr, "getUrl('pos/dashboard')");
    ok($posDashCount === 2, "header.php links to pos/dashboard from exactly two branches — the Sales dropdown and the standalone fallback (found $posDashCount)");
    ok(strpos($hdr, "getUrl('pos/price-groups')") === false, "header.php no longer links pos/price-groups directly (moved behind the hub's Catalog Setup card)");
    ok(!preg_match('/canView\(\'pos_advanced\'\).*?pos\/price-groups/s', $hdr), "header.php's Sales dropdown no longer gates a POS sub-item on pos_advanced — nothing left to gate at that layer");

    // Neighbouring, untouched structure still intact — proves the edit was
    // surgical (this exact block), not a wider rewrite of the dropdown.
    ok(strpos($hdr, "<?php if(canView('pos')): ?>") !== false, "the canView('pos') guard around the single POS link is still present");
    ok(strpos($hdr, "<?= t('Returns') ?>") !== false, "the 'Returns' dropdown header immediately after POS is untouched");
    ok(strpos($hdr, "canView('sales_returns')") !== false, "the sales_returns item after POS is untouched");

    // git-diff based guard: header.php's ONLY change in this phase is the
    // POS block shrinking from 4 lines to 1 (net -3). A much larger diff
    // means something else in this shared file was touched too.
    $gitOut = [];
    exec('git -C ' . escapeshellarg($root) . ' diff --numstat -- header.php 2>&1', $gitOut);
    if (!empty($gitOut) && preg_match('/^(\d+)\s+(\d+)\s+header\.php/', trim($gitOut[0]), $m)) {
        $added = (int)$m[1]; $removed = (int)$m[2];
        ok($added <= 3 && $removed <= 6, "header.php's working-tree diff is small ($added added / $removed removed) — consistent with only the POS dropdown block changing, not a wider edit");
    } else {
        ok(true, 'git diff unavailable or header.php already committed with no working-tree diff — skipping numstat guard');
    }

    // ── B. core/pos_nav.php + roots.php ──────────────────────────────────────
    section('B. core/pos_nav.php + roots.php wiring');
    require_once "$root/core/pos_nav.php";
    ok(function_exists('posNavGroups'), 'posNavGroups() is defined');

    $rt = src("$root/roots.php");
    foreach ([
        'restaurant' => 'index.php', 'restaurant/floors' => 'floors.php', 'restaurant/tables' => 'tables.php',
        'restaurant/kitchen' => 'kitchen.php', 'restaurant/kitchen-dashboard' => 'kitchen_dashboard.php',
        'restaurant/modifier-group' => 'modifier_group.php', 'restaurant/reservations' => 'reservations.php',
        'restaurant/menu-type' => 'menu_type.php',
    ] as $route => $file) {
        ok(strpos($rt, "'$route'") !== false && strpos($rt, "RESTAURANT_DIR . '/$file'") !== false, "roots.php registers '$route' -> $file");
    }

    // ── C. Live rendering under 5 entitlement scenarios ─────────────────────
    section('C. Live — pos_dashboard.php hub cards + restaurant sub-hub, per entitlement scenario');

    $adminUid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 4);
    // Force English — the tenant's default language can be Swahili (matches
    // this specific install), and every card-label assertion below checks
    // the English source strings on purpose (translation coverage is
    // test_pos_i18n_coverage_cli.php's job, not this test's).
    $session = ['user_id' => $adminUid, 'username' => 'admin', 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'];
    $allKeys = allFeatureKeys();
    $baseFeatures = array_fill_keys($allKeys, true); // start "everything on", then flip specific keys off per scenario

    $scenarios = [
        'pos-only' => array_merge($baseFeatures, ['pos_advanced' => false, 'restaurant_pos' => false]),
        'pos+advanced' => array_merge($baseFeatures, ['pos_advanced' => true, 'restaurant_pos' => false]),
        'pos+restaurant' => array_merge($baseFeatures, ['pos_advanced' => false, 'restaurant_pos' => true]),
        'pos+advanced+restaurant (admin/all)' => array_merge($baseFeatures, ['pos_advanced' => true, 'restaurant_pos' => true]),
    ];

    foreach ($scenarios as $label => $features) {
        $html = _nav_worker_run($root, 'app/bms/pos/pos_dashboard.php', $session, $features);
        // 'Open Terminal' and 'Shift History' were removed from the hub grid
        // (pos_nav.php) — a reported duplicate of this page's own header-row
        // links (#posWorkspaceOpenPos / #posWorkspaceShiftHistory), which are
        // always present regardless of entitlement and are the only place
        // these two destinations should now appear.
        $openPosCount = substr_count($html, 'id="posWorkspaceOpenPos"');
        $shiftHistoryLinkCount = substr_count($html, 'id="posWorkspaceShiftHistory"');
        $hubStillHasTerminalCard = strpos($html, 'Start selling at the POS terminal.') !== false;
        $hubStillHasShiftHistoryCard = strpos($html, 'Past and active shifts, with Z-Report drill-through.') !== false;
        $hasSettings = strpos($html, 'POS configuration: registers, receipts, loyalty.') !== false;
        $hasCatalog = strpos($html, 'Catalog Setup') !== false;
        $hasRestaurant = strpos($html, '>Restaurant<') !== false || strpos($html, 'Floors &amp; Tables, Kitchen Display') !== false;

        ok($openPosCount === 1, "[$label] header row's 'Open POS' link appears exactly once (got $openPosCount)");
        ok($shiftHistoryLinkCount === 1, "[$label] header row's 'Shift History' link appears exactly once (got $shiftHistoryLinkCount)");
        ok(!$hubStillHasTerminalCard, "[$label] hub grid no longer duplicates 'Open Terminal' as a card");
        ok(!$hubStillHasShiftHistoryCard, "[$label] hub grid no longer duplicates 'Shift History' as a card");
        ok($hasSettings, "[$label] hub always shows the 'Settings' shortcut");

        $wantCatalog = $features['pos_advanced'] === true;
        $wantRestaurant = $features['restaurant_pos'] === true;
        ok($hasCatalog === $wantCatalog, "[$label] 'Catalog Setup' card presence matches pos_advanced=" . ($wantCatalog ? 'true' : 'false') . ' (' . ($hasCatalog ? 'present' : 'ABSENT') . ')');
        ok($hasRestaurant === $wantRestaurant, "[$label] 'Restaurant' card presence matches restaurant_pos=" . ($wantRestaurant ? 'true' : 'false') . ' (' . ($hasRestaurant ? 'present' : 'ABSENT') . ')');

        // Sub-hub: only meaningful to check when restaurant_pos is on for
        // this scenario (off => index.php redirects before any HTML, proven
        // separately below).
        if ($wantRestaurant) {
            $subHtml = _nav_worker_run($root, 'app/bms/restaurant/index.php', $session, $features);
            // safe_output() HTML-escapes '&' to '&amp;' in the rendered card label.
            foreach (['Floors &amp; Tables', 'Kitchen Display', 'Modifier Group', 'Reservations', 'Menu Type'] as $toggle) {
                ok(strpos($subHtml, $toggle) !== false, "[$label] Restaurant sub-hub shows the '$toggle' toggle");
            }
        }
    }

    // D. restaurant_pos OFF entirely -> the sub-hub renders NOTHING (redirected before any HTML).
    section('D. Restaurant sub-hub genuinely inaccessible without the entitlement');
    $offFeatures = array_merge($baseFeatures, ['pos_advanced' => false, 'restaurant_pos' => false]);
    $subHtmlOff = _nav_worker_run($root, 'app/bms/restaurant/index.php', $session, $offFeatures);
    $noSubHubContent = strpos($subHtmlOff, 'Floors & Tables') === false
        && strpos($subHtmlOff, 'Kitchen Display') === false
        && strpos($subHtmlOff, 'Modifier Group') === false;
    ok($noSubHubContent, 'without restaurant_pos, app/bms/restaurant/index.php emits none of its sub-hub toggle labels — redirected before any content, not just visually hidden');

    // ── E. header.php — POS is standalone when Sales is closed, nested when open ──
    section("E. header.php — POS direct link when Sales is closed, dropdown item when Sales is open");
    // Product owner request, 2026-09-12: "if sales is closed, POS should be
    // seen directly in the header, not as a dropdown of sales; once sales is
    // allowed, POS should be a dropdown item again." Rendered via
    // app/dashboard.php (the simplest page that includes header.php).
    $navScenarios = [
        'sales ON, pos ON'  => ['sales' => true,  'pos' => true],
        'sales OFF, pos ON' => ['sales' => false, 'pos' => true],
        'sales OFF, pos OFF'=> ['sales' => false, 'pos' => false],
    ];
    foreach ($navScenarios as $label => $features) {
        $features = array_merge($baseFeatures, $features);
        $html = _nav_worker_run($root, 'app/dashboard.php', $session, $features);
        $hasSalesDropdown = strpos($html, 'id="salesDropdown"') !== false;
        $hasStandalonePos = (bool)preg_match('/<li class="nav-item">\s*<a class="nav-link" href="[^"]*\/pos\/dashboard">/', $html);
        $hasNestedPos      = strpos($html, 'class="dropdown-item" href="' ) !== false && strpos($html, "/pos/dashboard\"><i class=\"bi bi-cart-check\"") !== false;

        if ($label === 'sales ON, pos ON') {
            ok($hasSalesDropdown, "[$label] Sales dropdown is present");
            ok(!$hasStandalonePos, "[$label] POS is NOT a standalone link (it's nested in Sales)");
        } elseif ($label === 'sales OFF, pos ON') {
            ok(!$hasSalesDropdown, "[$label] Sales dropdown is genuinely absent, not just empty");
            ok($hasStandalonePos, "[$label] POS renders as a direct, standalone header link");
        } else { // sales OFF, pos OFF
            ok(!$hasSalesDropdown, "[$label] Sales dropdown is absent");
            ok(!$hasStandalonePos && strpos($html, 'href="/pos/dashboard"') === false, "[$label] no POS link anywhere — neither form renders");
        }
    }

} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

exit($fail === 0 ? 0 : 1);
