<?php
/**
 * POS "Simple Mode" — CLI regression suite
 *   php tests/test_pos_simple_mode_cli.php
 *
 * Simple Mode is a display-only, tenant-wide preference for a shop with no
 * accountant. It must NEVER disable ledger posting
 * (.claude/reporting-source.md mandates every financial report reads only
 * the posted journal, unconditionally) — it only changes what the UI shows:
 * the Reports mega-menu collapses to a short list, Chart of
 * Accounts/Journals hide from the Finance menu, and the dashboard's
 * Performance Overview chart swaps to a plain Bought vs Sold view sourced
 * from pos_sales (core/pos_dashboard_metrics.php).
 *
 * SUPERADMIN-ONLY, by design: a tenant's own admin has no UI or endpoint
 * that can change this setting — see tests/test_superadmin_pos_simple_mode_cli.php
 * for the actual write path (app/superadmin/tenant_view.php > Point of Sale
 * > More -> actions/superadmin_tenant_pos_simple_mode.php ->
 * core/tenant_admin.php::setTenantPosSimpleMode()). Section F here proves
 * the negative: no tenant-facing page or file offers a way to set it.
 *
 *   A. STATIC  — every touched/new file lints clean.
 *   B. WIRING  — source patterns: menu gates present, chart swap wired,
 *                ledger posting untouched.
 *   C. DEFAULT — posSimpleModeEnabled() is OFF when unset (default off,
 *                per requirement: "by default remain as is").
 *   D. ROUND-TRIP — save_setting()/posSimpleModeEnabled() really reads back
 *                what was saved, across a fresh process (matches real
 *                save-then-reload usage; get_setting() caches per-process).
 *   E. LIVE    — posSimpleBuySellSeries() reconciles to two independently
 *                derived totals: a direct-SQL "sold" total, and a per-sale
 *                loop over the existing, separately-tested posSaleCogs()
 *                for "bought".
 *   F. NO TENANT SELF-SERVICE — the tenant-facing "More" button/endpoint
 *                this feature briefly had is genuinely gone, not just hidden.
 *
 * Read-only except D, which restores the setting to OFF when done.
 * Exit 0 = all pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function approx($a, $b) { return abs((float)$a - (float)$b) < 0.01; }
function src($p) { return is_file($p) ? file_get_contents($p) : ''; }
function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'possimple_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    $rootEsc = addslashes($root);

    $files = [
        'header.php'                                    => "$root/header.php",
        'core/pos_nav.php'                               => "$root/core/pos_nav.php",
        'core/pos_dashboard_metrics.php'                 => "$root/core/pos_dashboard_metrics.php",
        'app/dashboard.php'                              => "$root/app/dashboard.php",
        'app/constant/settings/pos_config_settings.php'  => "$root/app/constant/settings/pos_config_settings.php",
        'api/pos/get_simple_dashboard_chart.php'         => "$root/api/pos/get_simple_dashboard_chart.php",
        'app/constant/settings/available_modules.php'    => "$root/app/constant/settings/available_modules.php",
    ];

    // ── A. Lint ────────────────────────────────────────────────
    section('A. Lint');
    foreach ($files as $name => $path) {
        ok(is_file($path), "$name exists");
        $o = []; $rc = 0; exec('php -l ' . escapeshellarg($path) . ' 2>&1', $o, $rc);
        ok($rc === 0, "$name lint-clean");
    }

    // ── B. Wiring / source patterns ───────────────────────────
    section('B. Wiring');

    $nav = src($files['core/pos_nav.php']);
    ok(strpos($nav, 'function posSimpleModeEnabled') !== false, 'posSimpleModeEnabled() defined');
    ok(strpos($nav, "get_setting('pos_simple_mode', '0')") !== false, "posSimpleModeEnabled() reads the 'pos_simple_mode' setting");

    $metrics = src($files['core/pos_dashboard_metrics.php']);
    ok(strpos($metrics, 'function posSimpleBuySellSeries') !== false, 'posSimpleBuySellSeries() defined');
    // Isolate the function's own SQL body (up to the next top-level function),
    // not the whole file, so a doc comment mentioning "journal_entries" (to
    // explain why it's avoided) can't produce a false failure here.
    $fnStart = strpos($metrics, 'function posSimpleBuySellSeries');
    $fnEnd   = strpos($metrics, "\nif (!function_exists(", $fnStart);
    $fnBody  = substr($metrics, $fnStart, ($fnEnd !== false ? $fnEnd - $fnStart : null));
    ok(strpos($fnBody, 'FROM journal_entries') === false && strpos($fnBody, 'JOIN journal_entries') === false,
        'posSimpleBuySellSeries() SQL never queries journal_entries (cash-basis, not GL)');
    ok(strpos($fnBody, 'FROM pos_sales') !== false, 'posSimpleBuySellSeries() SQL reads pos_sales directly');

    $header = src($files['header.php']);
    ok(strpos($header, "require_once __DIR__ . '/core/pos_nav.php';") !== false, 'header.php loads core/pos_nav.php');
    ok(strpos($header, 'if (posSimpleModeEnabled()):') !== false, 'Reports menu branches on posSimpleModeEnabled()');
    // Uses the SAME English t()-keys as the full mega-menu (not new hardcoded
    // Swahili literals) so the English locale still reads correctly and the
    // Swahili labels ("Ripoti ya Mauzo" etc.) come from the existing lang/sw.php
    // translations of these exact keys, verified below.
    ok(strpos($header, "t('Business Reports')") !== false, 'Simplified Reports list reuses the Business Reports header key');
    $simpleMenuStart = strpos($header, 'posSimpleModeEnabled()): ?>');
    $simpleMenuEnd   = strpos($header, '<?php else: ?>', $simpleMenuStart);
    $simpleMenuHtml  = substr($header, $simpleMenuStart, $simpleMenuEnd - $simpleMenuStart);
    foreach (['sales_report' => 'Sales Report', 'purchase_report' => 'Purchase Report', 'inventory_report' => 'Inventory Report', 'expense_report' => 'Expense Report'] as $pk => $key) {
        ok(strpos($simpleMenuHtml, "canView('$pk')") !== false && strpos($simpleMenuHtml, "t('$key')") !== false, "Simplified list: $key, gated on canView('$pk')");
    }
    require_once "$root/core/i18n.php";
    loadLanguage('sw');
    foreach (['Business Reports' => 'Ripoti za Biashara', 'Sales Report' => 'Ripoti ya Mauzo', 'Purchase Report' => 'Ripoti ya Ununuzi', 'Inventory Report' => 'Ripoti ya Ghala', 'Expense Report' => 'Ripoti ya Matumizi'] as $en => $sw) {
        ok(t($en) === $sw, "t('$en') under Swahili == '$sw' (got '" . t($en) . "')");
    }
    loadLanguage('en');
    ok(strpos($header, "canView('chart_of_accounts') && !posSimpleModeEnabled()") !== false, 'Chart of Accounts hidden in Simple Mode');
    ok(strpos($header, "canView('journals') && !posSimpleModeEnabled()") !== false, 'Journals hidden in Simple Mode');
    // Balanced if/endif around the Reports branch — a stray endif here would
    // silently swallow every nav item rendered after it site-wide.
    ok(substr_count($header, '<?php if (posSimpleModeEnabled()):') === substr_count($header, "// posSimpleModeEnabled() ?>"), 'Reports simple/full branch if/endif balanced');

    $dash = src($files['app/dashboard.php']);
    ok(strpos($dash, '$pos_simple_mode = posSimpleModeEnabled();') !== false, 'dashboard.php computes $pos_simple_mode');
    ok(strpos($dash, 'const POS_SIMPLE_MODE') !== false, 'dashboard.php exposes POS_SIMPLE_MODE to JS');
    ok(strpos($dash, "api/pos/get_simple_dashboard_chart.php") !== false, 'dashboard.php can call the simple-mode chart endpoint');
    ok(strpos($dash, "api/get_performance_data.php") !== false, 'dashboard.php still calls the ledger-based endpoint for non-simple tenants');
    ok(strpos($dash, "t('Bought vs Sold')") !== false, 'dashboard.php uses the Bought vs Sold title key (not a hardcoded Swahili literal)');
    ok(strpos($dash, "t('Sales')") !== false && strpos($dash, "t('Purchases')") !== false, 'dashboard.php reuses the existing Sales/Purchases keys for the simple chart legend');
    loadLanguage('sw');
    ok(t('Bought vs Sold') === 'Ununuzi na Mauzo', "t('Bought vs Sold') under Swahili == 'Ununuzi na Mauzo' (got '" . t('Bought vs Sold') . "')");
    loadLanguage('en');

    $chartApi = src($files['api/pos/get_simple_dashboard_chart.php']);
    ok(strpos($chartApi, "canView('pos')") !== false, 'get_simple_dashboard_chart.php gated on canView(pos)');
    ok(strpos($chartApi, "isAuthenticated()") !== false, 'get_simple_dashboard_chart.php gated on isAuthenticated()');
    ok(strpos($chartApi, "scopeFilterSqlNullable('project', 'ps')") !== false, 'get_simple_dashboard_chart.php project-scoped (§23)');
    ok(strpos($chartApi, "scopeFilterSqlNullable('warehouse', 'ps')") !== false, 'get_simple_dashboard_chart.php warehouse-scoped');

    // Ledger posting itself must be completely untouched by this feature —
    // Simple Mode is display-only. Spot-check the posting core files carry
    // no reference to the new setting at all.
    foreach (['core/sales_posting.php', 'core/purchase_posting.php', 'core/revenue_posting.php'] as $f) {
        $p = "$root/$f";
        if (is_file($p)) {
            ok(strpos(src($p), 'pos_simple_mode') === false, "$f does not reference pos_simple_mode (ledger posting unconditional)");
        }
    }

    // ── C. Default is OFF ─────────────────────────────────────
    section('C. Default is OFF when unset');

    $hadRow = (bool)$pdo->query("SELECT 1 FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetch();
    $originalValue = $hadRow
        ? $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn()
        : null;

    if (!$hadRow) {
        $offResult = runPhp("require '$rootEsc/roots.php'; require '$rootEsc/core/pos_nav.php'; echo posSimpleModeEnabled() ? 'ON' : 'OFF';");
        ok($offResult === 'OFF', "posSimpleModeEnabled() is OFF with no row in system_settings (got '$offResult')");
    } else {
        ok(true, "system_settings already has a 'pos_simple_mode' row (value='$originalValue') — unset-default check skipped, not a fresh-data condition");
    }

    // ── D. Save -> read round-trip (fresh process each time) ──
    section('D. Save/read round-trip');

    $setResult = runPhp("require '$rootEsc/roots.php'; save_setting('pos_simple_mode', '1'); echo 'SAVED';");
    ok($setResult === 'SAVED', 'save_setting(pos_simple_mode, 1) runs cleanly');

    $onResult = runPhp("require '$rootEsc/roots.php'; require '$rootEsc/core/pos_nav.php'; echo posSimpleModeEnabled() ? 'ON' : 'OFF';");
    ok($onResult === 'ON', "a fresh process reads ON immediately after save (got '$onResult')");

    // Restore — either the original stored value, or unset back to OFF.
    if ($hadRow && $originalValue !== null) {
        runPhp("require '$rootEsc/roots.php'; save_setting('pos_simple_mode', " . var_export($originalValue, true) . "); echo 'RESTORED';");
    } else {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    }
    $restored = runPhp("require '$rootEsc/roots.php'; require '$rootEsc/core/pos_nav.php'; echo posSimpleModeEnabled() ? 'ON' : 'OFF';");
    $expectedRestored = ($hadRow && $originalValue === '1') ? 'ON' : 'OFF';
    ok($restored === $expectedRestored, "setting restored to its original state after the test (got '$restored', expected '$expectedRestored')");

    // ── E. Live reconciliation — posSimpleBuySellSeries() ─────
    section('E. Live reconciliation (posSimpleBuySellSeries)');

    if (!(bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch()) {
        ok(true, 'pos_sales absent — live reconciliation skipped');
    } else {
        require_once "$root/core/pos_dashboard_metrics.php";
        require_once "$root/core/sales_posting.php"; // posSaleCogs() — independent cross-check

        $from = '2000-01-01';
        $to   = date('Y-m-d');
        $series = posSimpleBuySellSeries($pdo, $from, $to, 'monthly', '');
        ok(is_array($series), 'posSimpleBuySellSeries() returns an array');

        $recOrig = "ps.sale_status IN ('completed','partially_refunded','refunded') AND ps.is_return_sale = 0 AND ps.invoice_id IS NULL";
        $recRet  = "ps.is_return_sale = 1 AND ps.sale_status NOT IN ('voided','cancelled') AND ps.invoice_id IS NULL";

        // "Sold" — independent direct-SQL total (no period grouping) must equal
        // the sum of every period the function returned.
        $soldTotal = (float)$pdo->query("
            SELECT COALESCE(SUM(CASE WHEN $recOrig THEN ps.grand_total - ps.tax_amount
                                      WHEN $recRet  THEN -(ps.grand_total - ps.tax_amount) ELSE 0 END), 0)
              FROM pos_sales ps WHERE DATE(ps.sale_date) BETWEEN '$from' AND '$to'
        ")->fetchColumn();
        $sumSold = array_sum(array_column($series, 'sold'));
        ok(approx($sumSold, $soldTotal), sprintf('sum(sold) across periods == direct SQL total (%.2f)', $soldTotal));

        // "Bought" — independent per-sale loop using the existing, separately
        // tested posSaleCogs(), a different code path from the function's own
        // single aggregate query.
        $origIds = $pdo->query("SELECT sale_id FROM pos_sales ps WHERE $recOrig AND DATE(ps.sale_date) BETWEEN '$from' AND '$to'")->fetchAll(PDO::FETCH_COLUMN);
        $retIds  = $pdo->query("SELECT sale_id FROM pos_sales ps WHERE $recRet  AND DATE(ps.sale_date) BETWEEN '$from' AND '$to'")->fetchAll(PDO::FETCH_COLUMN);
        $boughtTotal = 0.0;
        foreach ($origIds as $sid) { $boughtTotal += posSaleCogs($pdo, (int)$sid); }
        foreach ($retIds  as $sid) { $boughtTotal -= posSaleCogs($pdo, (int)$sid); }
        $sumBought = array_sum(array_column($series, 'bought'));
        ok(approx($sumBought, $boughtTotal), sprintf('sum(bought) across periods == independent per-sale posSaleCogs() total (%.2f)', $boughtTotal));

        $profitOk = true;
        foreach ($series as $row) { if (!approx($row['profit'], $row['sold'] - $row['bought'])) { $profitOk = false; break; } }
        ok($profitOk, 'profit == sold - bought for every returned period');

        $empty = posSimpleBuySellSeries($pdo, $from, $to, 'monthly', ' AND 1=0 ');
        ok($empty === [], 'an always-false scope clause yields an empty series (scope really applied to the query)');

        $fallback = posSimpleBuySellSeries($pdo, $from, $to, 'not-a-real-period', '');
        ok(count($fallback) === count($series), 'an unknown period string falls back to monthly grouping (' . count($fallback) . ' periods)');
    }

    // ── F. No tenant self-service — superadmin-only, genuinely ────
    section('F. No tenant-facing way to change Simple Mode (superadmin-only)');

    ok(!is_file("$root/api/pos/save_simple_mode.php"), 'the old tenant-facing save endpoint is gone, not just unreachable');

    $avail = src($files['app/constant/settings/available_modules.php']);
    ok(strpos($avail, 'posSimpleModeModal') === false, 'Available Modules has no Simple Mode modal/button left');
    ok(strpos($avail, 'pos_simple_mode') === false, 'Available Modules never mentions pos_simple_mode at all');

    $settingsPage = src($files['app/constant/settings/pos_config_settings.php']);
    ok(strpos($settingsPage, 'pos_simple_mode') === false, 'POS Settings never mentions pos_simple_mode at all — no checkbox, no save path');

} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage());
}

echo "\n";
exit($fail === 0 ? 0 : 1);
