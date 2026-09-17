<?php
/**
 * Dashboard KPI strip — Credit (Madeni) + Monthly Expenses relocated into
 * the top one-row Statistics Cards strip (Simple POS) — CLI regression
 *   php tests/test_dashboard_kpi_row_cli.php
 *
 * Request (2026-09-16 follow-up): move the Credit (Madeni) and Monthly
 * Expenses cards out of the lower "Quick Stats Row" and into the top KPI
 * strip (Monthly Revenue / Today's POS Sales / Overdue Invoices / Inventory
 * Value / Total Shops), positioned right after the Quick Links tile grid,
 * so the strip totals 6 cards for a typical Simple POS tenant. Two explicit
 * constraints: (1) they must sit in ONE row on desktop, equal flexible
 * width, same as the existing cards; (2) no red — both cards previously
 * used text-danger for their headline figure.
 *
 * Also reported: "Monthly Expenses" showed in English even with Swahili
 * selected — lang/sw.php was simply missing the key (and 'Records').
 *
 *   A. STATIC   — dashboard.php lints clean.
 *   B. WIRING   — both cards removed from the lower Quick Stats Row (no
 *                orphaned markup) and present in the top KPI strip, using
 *                that strip's own class conventions (dashboard-stat-link,
 *                format_currency, <h4>/<p> layout) rather than the lower
 *                row's card-header/<h3> layout they used before.
 *   C. COLOR    — neither new card uses bg-danger, text-danger, or any
 *                inline red; the KPI strip's existing cards (which the user
 *                did NOT complain about) are untouched.
 *   D. LAYOUT   — the KPI row carries the `dashboard-kpi-row` class and a
 *                >=1200px media query forces flex-wrap: nowrap with equal
 *                flex-basis, so any number of cards (4-7 depending on a
 *                tenant's permissions) always fits in one row on desktop.
 *   E. TRANSLATION — 'Monthly Expenses' and 'Records' resolve to real
 *                Swahili strings now.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 70) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 60) . "`"); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// ─────────────────────────────────────────────────────────────────────────
section('1. Static');
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("$root/app/dashboard.php") . ' 2>&1', $out, $rc);
$rc === 0 ? pass('app/dashboard.php') : fail('php -l failed: ' . implode(' ', $out));

// ─────────────────────────────────────────────────────────────────────────
section('2. Removed from the lower Quick Stats Row');
$dash = src($root, 'app/dashboard.php');
hasnt($dash, 'bi-cash-coin text-danger me-2', 'the old red-headed Credit card header icon is gone');
hasnt($dash, "<?= t('View Who Owes Me') ?>", 'the old lower-row Credit card button is gone (moved card is a whole-card link, no separate button)');
hasnt($dash, "<?= t('View This Month\\'s Expenses') ?>", 'the old lower-row Monthly Expenses card button is gone (same reason)');

// ─────────────────────────────────────────────────────────────────────────
section('3. Present in the top KPI strip, matching that strip\'s own style');
has($dash, "<!-- 6. Credit (Madeni)", 'Credit (Madeni) card is now card #6 in the KPI strip');
has($dash, "<!-- 7. Monthly Expenses", 'Monthly Expenses card is now card #7 in the KPI strip');
has($dash, "format_currency(\$pos_credit_total)", 'Credit card uses format_currency() like its KPI-strip siblings (was plain number_format before)');
has($dash, "format_currency(\$pos_month_expense_amount)", 'Monthly Expenses card uses format_currency() like its KPI-strip siblings');
has($dash, "class=\"card bg-secondary text-white h-100\"", 'Credit card uses the KPI strip\'s solid-bg-color card style');
has($dash, "background-color:#6f42c1;", 'Monthly Expenses card uses a distinct (purple) solid background, matching the strip\'s style');

// ─────────────────────────────────────────────────────────────────────────
section('4. No red anywhere on either relocated card');
// Isolate just the two new card blocks to scope the red-check precisely
// (the rest of the KPI strip legitimately uses bg-warning/text-danger
// elsewhere, e.g. Overdue Invoices — that's not in scope here).
$madeniStart = strpos($dash, '<!-- 6. Credit (Madeni)');
$monthlyEnd  = strpos($dash, '<!-- Main Content Area -->', $madeniStart);
if ($madeniStart === false || $monthlyEnd === false) {
    fail('Could not isolate the two new card blocks for the color check');
} else {
    $block = substr($dash, $madeniStart, $monthlyEnd - $madeniStart);
    (strpos($block, 'text-danger') === false && strpos($block, 'bg-danger') === false && strpos($block, '#dc3545') === false)
        ? pass('No red (text-danger / bg-danger / #dc3545) in the Credit or Monthly Expenses card markup')
        : fail('Found red styling in the relocated cards — user explicitly asked for no red');
}

// ─────────────────────────────────────────────────────────────────────────
section('5. Desktop one-row layout');
has($dash, 'dashboard-kpi-row', 'KPI strip container carries the dashboard-kpi-row class');
has($dash, '@media (min-width: 1200px)', 'a desktop-width media query exists for the KPI strip');
has($dash, '.dashboard-kpi-row { flex-wrap: nowrap; }', 'desktop: the row is forced to never wrap');
has($dash, ".dashboard-kpi-row > .dashboard-stat-link { min-width: 0; flex: 1 1 0; }", 'desktop: every card gets equal flexible width regardless of count');

// ─────────────────────────────────────────────────────────────────────────
section('6. Translation coverage');
require_once "$root/core/i18n.php";
loadLanguage('sw');
foreach (['Monthly Expenses', 'Records'] as $key) {
    $sw = t($key);
    ($sw !== $key && $sw !== '')
        ? pass("'$key' has a real Swahili translation ('$sw')")
        : fail("'$key' falls back to raw English under sw locale");
}

// ─────────────────────────────────────────────────────────────────────────
section('7. Live HTTP render check (English + Swahili)');
global $pdo;
require_once "$root/core/pos_nav.php";
$base = getenv('BMS_TEST_URL') ?: 'http://dev.bms.local';
$reachable = false;
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
if (@file_get_contents("$base/login.php", false, $ctx) !== false) $reachable = true;

if (!$reachable) {
    echo "  \033[33m⚠ server not reachable at $base — skipping live render checks\033[0m\n";
} elseif (!function_exists('curl_init')) {
    echo "  \033[33m⚠ curl extension unavailable — skipping live render checks\033[0m\n";
} else {
    $wasSimple = posSimpleModeEnabled();
    save_setting('pos_simple_mode', '1');
    $adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();

    $probe = "$root/_dashboard_kpi_row_test_probe.php";
    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
if (($_GET['act'] ?? '') === 'login') {
    $_SESSION['user_id']  = (int)$_GET['uid'];
    $_SESSION['role_id']  = 1;
    $_SESSION['is_admin'] = true;
    if (!empty($_GET['lang'])) { $_SESSION['user_lang'] = $_GET['lang']; }
    loadUserPermissions(1);
    echo 'ok';
}
PHP);
    register_shutdown_function(function () use ($probe, $wasSimple) {
        if (is_file($probe)) @unlink($probe);
        if (!$wasSimple) save_setting('pos_simple_mode', '0');
    });

    function httpGet3($url, $cookieJar) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body];
    }

    if ($adminUid <= 0) {
        fail('No admin user found — cannot run live render checks');
    } else {
        $cookieJar = tempnam(sys_get_temp_dir(), 'kpitest_cookies_');
        httpGet3("$base/_dashboard_kpi_row_test_probe.php?act=login&uid=$adminUid&lang=en", $cookieJar);
        [$code, $body] = httpGet3("$base/dashboard", $cookieJar);

        (!preg_match('/Fatal error: Uncaught|Parse error: syntax error|<b>Fatal error<\/b>|<b>Parse error<\/b>/i', (string)$body))
            ? pass('dashboard.php has no PHP fatal/parse errors after the KPI-row changes')
            : fail('dashboard.php has a PHP error: ' . substr((string)$body, 0, 300));
        (strpos((string)$body, 'dashboard-kpi-row') !== false)
            ? pass('Live page includes the dashboard-kpi-row container')
            : fail('Live page missing the dashboard-kpi-row container');
        (strpos((string)$body, 'pos/credit-customers') !== false)
            ? pass('Live page includes the relocated Credit (Madeni) card link')
            : fail('Live page missing the Credit card link');

        $cookieJarSw = tempnam(sys_get_temp_dir(), 'kpitest_cookies_sw_');
        httpGet3("$base/_dashboard_kpi_row_test_probe.php?act=login&uid=$adminUid&lang=sw", $cookieJarSw);
        [$codeSw, $bodySw] = httpGet3("$base/dashboard", $cookieJarSw);
        (strpos((string)$bodySw, 'Matumizi ya Mwezi') !== false)
            ? pass('Live page shows "Matumizi ya Mwezi" (Monthly Expenses) under the sw locale — was raw English before this fix')
            : fail('Live page still shows raw English for Monthly Expenses under sw locale');

        @unlink($cookieJar);
        @unlink($cookieJarSw);
    }
}
