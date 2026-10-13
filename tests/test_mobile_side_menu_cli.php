<?php
/**
 * Simple POS phone navigation — side menu + 5-item bottom bar — CLI test
 *   php tests/test_mobile_side_menu_cli.php
 *
 * 2026-10-12 request (style "A" chosen from three mockups):
 *   - Bottom bar: 5 items — Dashboard, Products, Sell (raised, centre),
 *     Expenses, Reports. No "More".
 *   - ☰ opens one LEFT side menu holding what the old "More" and account
 *     sheets had (same canView() gates). It closes with ✕, a tap outside,
 *     a swipe to the left, the phone's Back button, or by choosing an item.
 *   - The bar hides only while a real keyboard is open (not when the
 *     browser's address bar moves, not for the hidden scanner field).
 *   - Desktop untouched (everything is d-lg-none).
 *
 * No HTTP, no DB. Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$h = (string)file_get_contents("$root/header.php");
$simpleStart = strrpos($h, '<?php if (posSimpleModeEnabled()): ?>');
$simpleEnd   = strpos($h, '<?php endif; // posSimpleModeEnabled() bottom bar ?>');
$sec = substr($h, (int)$simpleStart, (int)$simpleEnd - (int)$simpleStart);

section('1. Lint');
foreach (['header.php', 'app/dashboard.php', 'app/bms/pos/pos.php', 'lang/sw.php'] as $f) {
    ok(strpos((string)shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1'), 'No syntax errors') !== false, "$f lints clean");
}
ok($simpleStart !== false && $simpleEnd !== false && $simpleEnd > $simpleStart, 'Simple POS phone section found');

section('2. Bottom bar: 5 items, Sell in the middle, no More');
$navStart = strpos($sec, '<nav class="bms-bnav');
$nav = substr($sec, $navStart, strpos($sec, '</nav>', $navStart) - $navStart);
preg_match_all("/canView\\('(dashboard|products|pos|expenses)'\\)|hasReportsAccess\\(\\)/", $nav, $m);
ok($m[0] === ["canView('dashboard')", "canView('products')", "canView('pos')", "canView('expenses')", 'hasReportsAccess()'], 'order: Dashboard, Products, Sell, Expenses, Reports');
ok(strpos($nav, 'class="bn-item bn-sell') !== false && strpos($nav, 'bn-circle') !== false, 'Sell is the raised centre button');
ok(strpos($nav, "wLabel('POS', 'Sell')") !== false, 'Sell label follows shop mode (Uza / Sell)');
ok(strpos($nav, 'bmsMoreSheet') === false && strpos($nav, "t('More')") === false, 'no "More" item');
ok(strpos($sec, '.bms-bnav { transform: translateZ(0)') !== false && strpos($sec, 'overscroll-behavior-y: none') !== false, 'bar on its own layer, page bounce disabled on phones');

section('3. One left side menu replaces the More + account sheets');
ok(strpos($h, 'id="bmsMoreSheet"') === false && strpos($h, 'id="bmsAccountSheet"') === false, 'old bottom sheets removed');
ok(strpos($sec, 'class="offcanvas offcanvas-start bms-side d-lg-none d-print-none" tabindex="-1" id="bmsSideMenu"') !== false, 'side menu slides from the left, phones only');
ok(strpos($h, 'data-bs-toggle="offcanvas" data-bs-target="#bmsSideMenu"') !== false, '☰ button opens the side menu');
ok(strpos($sec, 'class="bms-side-close" data-bs-dismiss="offcanvas"') !== false, '✕ close button');
foreach (["canView('customers')", "canView('suppliers')", "getUrl('services')", "canView('pos_restock')", "canView('field_visits')", 'if ($_set_sys_visible)', "canView('pos_config_settings')", "getUrl('my_settings')", "getUrl('help')", "getUrl('logout')"] as $needle) {
    ok(strpos($sec, $needle) !== false, "side menu keeps $needle");
}
ok(strpos($sec, "<?php if (canView('customers') || canView('suppliers') || canView('products') || (canView('pos') && canView('pos_restock')) || canView('field_visits')): ?>") !== false, '"My Business" group hidden when the user can see none of it');

section('4. Ways to close');
ok(strpos($sec, "history.pushState({ bmsSideMenu: true }, '')") !== false && strpos($sec, "window.addEventListener('popstate'") !== false, 'phone Back button closes the menu');
ok(strpos($sec, "if (pushed && !leaving) { pushed = false; history.back(); }") !== false, 'closing by ✕/tap removes the extra history step; choosing a link does not');
ok(strpos($sec, "side.addEventListener('touchmove'") !== false && strpos($sec, 'if (dx < -70) { oc.hide(); }') !== false, 'swipe left closes');

section('5. Keyboard rule');
ok(strpos($sec, "if ((el.getAttribute('inputmode') || '') === 'none') return false;") !== false, 'hidden scanner field (inputmode=none) never hides the bar');
ok(strpos($sec, "typing() && window.visualViewport.height < maxH * 0.75") !== false, 'bar hides only when a text field has focus and the screen shrank');

section('6. Related fixes');
$sw = include "$root/lang/sw.php";
foreach (['My Account' => 'Akaunti Yangu', 'Menu' => 'Menyu', 'Open menu' => 'Fungua menyu', 'Close menu' => 'Funga menyu', 'Overview' => 'Muhtasari', 'LOW STOCK' => 'STOKI KIDOGO'] as $k => $v) {
    ok(($sw[$k] ?? null) === $v, "sw: \"$k\" => \"$v\"");
}
ok(strpos((string)file_get_contents("$root/app/bms/pos/pos.php"), "wLabel('Workspace', 'Overview')") !== false, 'Sell screen: "Workspace" button reads "Muhtasari / Overview" in shop mode');
ok(strpos((string)file_get_contents("$root/app/dashboard.php"), '<div class="d-flex flex-row flex-wrap gap-2 w-100 w-md-auto') !== false, 'dashboard: date + Quick Actions buttons wrap instead of overflowing the phone screen');
