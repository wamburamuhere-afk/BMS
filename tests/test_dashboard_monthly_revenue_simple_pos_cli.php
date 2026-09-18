<?php
/**
 * Dashboard "Monthly Revenue" KPI card — hidden for Simple POS tenants —
 * CLI regression
 *   php tests/test_dashboard_monthly_revenue_simple_pos_cli.php
 *
 * Request (2026-09-17): the Monthly Revenue card is the glProfitLoss()
 * Income Statement figure (double-entry ledger, accrual basis) — a Simple
 * POS shop owner doesn't reason in those terms, and Today's POS Sales
 * already answers "how much did I sell" for that tenant shape. Same
 * precedent already set for the Inventory Value card
 * (test_dashboard_kpi_row_cli.php's era). Fix: gate the card's display
 * condition on `!$pos_simple_mode`, changing nothing else — the underlying
 * glProfitLoss() query still runs (still feeds the Performance Overview
 * chart) and every other tenant's card is untouched.
 *
 *   A. STATIC — dashboard.php lints clean; the card's if() now requires
 *              !$pos_simple_mode, same pattern as the Inventory Value card.
 *   B. LIVE   — with pos_simple_mode=1, the income_statement deep-link
 *              (the card's own href) is absent from the rendered page;
 *              with it back to 0, the link is present again for an admin
 *              (who has invoices/reports access either way).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function src(string $root, string $rel): string { $p = "$root/$rel"; return file_exists($p) ? file_get_contents($p) : ''; }
function has(string $hay, string $needle, string $label): void { strpos($hay, $needle) !== false ? pass($label) : fail("$label — missing `" . substr($needle, 0, 90) . "`"); }
function hasnt(string $hay, string $needle, string $label): void { strpos($hay, $needle) === false ? pass($label) : fail("$label — found `" . substr($needle, 0, 90) . "`"); }

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

$dash = src($root, 'app/dashboard.php');
has($dash, "<?php if(!\$pos_simple_mode && (canView('invoices') || canView('sales_report') || hasReportsAccess())): ?>",
    'Monthly Revenue card if() now requires !$pos_simple_mode');
has($dash, "<?php if(!\$pos_simple_mode && (canView('products') || canView('inventory_report'))): ?>",
    'sanity: Inventory Value card keeps its own pre-existing !$pos_simple_mode gate (precedent unchanged)');

// ─────────────────────────────────────────────────────────────────────────
section('2. Live HTTP render check (Simple POS on vs off)');
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
    $adminUid = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.is_admin = 1 LIMIT 1")->fetchColumn();

    $probe = "$root/_dashboard_revenue_test_probe.php";
    file_put_contents($probe, <<<'PHP'
<?php
require_once __DIR__ . '/roots.php';
if (($_GET['act'] ?? '') === 'login') {
    $_SESSION['user_id']  = (int)$_GET['uid'];
    $_SESSION['role_id']  = 1;
    $_SESSION['is_admin'] = true;
    loadUserPermissions(1);
    echo 'ok';
}
PHP);
    register_shutdown_function(function () use ($probe, $wasSimple) {
        if (is_file($probe)) @unlink($probe);
        save_setting('pos_simple_mode', $wasSimple ? '1' : '0');
    });

    function httpGet4($url, $cookieJar) {
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
        $cookieJar = tempnam(sys_get_temp_dir(), 'revtest_cookies_');
        httpGet4("$base/_dashboard_revenue_test_probe.php?act=login&uid=$adminUid", $cookieJar);

        save_setting('pos_simple_mode', '1');
        [, $bodySimple] = httpGet4("$base/dashboard", $cookieJar);
        (!preg_match('/Fatal error: Uncaught|Parse error: syntax error/i', (string)$bodySimple))
            ? pass('dashboard.php renders with no PHP fatal under Simple POS')
            : fail('dashboard.php has a PHP error under Simple POS: ' . substr((string)$bodySimple, 0, 300));
        (strpos((string)$bodySimple, 'income_statement?start_date=') === false)
            ? pass('Simple POS: Monthly Revenue card (income_statement deep-link) is ABSENT')
            : fail('Simple POS: Monthly Revenue card is still rendered — should be hidden');
        (strpos((string)$bodySimple, "id=\"pos-shop-total-sales\"") !== false || strpos((string)$bodySimple, "Today's POS Sales") !== false)
            ? pass("Simple POS: Today's POS Sales card is still present (unaffected)")
            : fail("Simple POS: Today's POS Sales card missing — should be untouched");

        save_setting('pos_simple_mode', '0');
        [, $bodyNormal] = httpGet4("$base/dashboard", $cookieJar);
        (strpos((string)$bodyNormal, 'income_statement?start_date=') !== false)
            ? pass('Normal tenant: Monthly Revenue card is PRESENT (unaffected by this fix)')
            : fail('Normal tenant: Monthly Revenue card missing — over-hidden');

        @unlink($cookieJar);
    }
}
