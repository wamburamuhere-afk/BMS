<?php
/**
 * Sales by User Report + Profit Report (Simple POS only) — CLI test
 *   php tests/test_pos_user_sales_and_profit_reports_cli.php
 *
 * 2026-09-17: two new Reports-menu entries for Simple POS shops —
 *   - pos_user_sales_report (app/constant/reports/pos_user_sales_report.php +
 *     api/account/get_user_sales_report.php) — per-cashier POS sales totals
 *     and a product-level drill-down ("what did this cashier sell, worth how much").
 *   - pos_profit_report (app/constant/reports/pos_profit_report.php +
 *     api/account/get_profit_report.php) — gross/net profit for a chosen date
 *     range, wrapping the one canonical glProfitLoss() engine
 *     (.claude/reporting-source.md) — no raw POS/expense SQL of its own.
 *
 * Verifies:
 *   1. All five touched/new files lint-clean.
 *   2. Source wiring — permission gates (canView('pos') + the dedicated
 *      page_key), the posSimpleModeEnabled() redirect/404, project/warehouse
 *      scope checks, and that the profit API calls glProfitLoss() rather than
 *      re-deriving its own SQL.
 *   3. Live (in-process, admin session):
 *      - Sales by User: per-cashier rows sum to the real completed-POS total;
 *        the items drill-down for one cashier returns rows.
 *      - Profit Report: matches a direct glProfitLoss() call for the same
 *        range/scope exactly (the API must not diverge from the engine);
 *        net_margin_pct math checks out; monthly trend is capped at 24 points.
 *      - Simple Mode OFF → both APIs report "not available", not a 500.
 *      - Non-admin, out-of-scope warehouse_id → 403 on both APIs.
 *
 * Exit 0 = all pass.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/project_scope.php";
require_once "$root/core/pos_nav.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$passes = 0; $failures = 0;
function pass(string $m): void { global $passes;   $passes++;   echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $failures; $failures++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $passes, $failures;
    static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$passes\033[0m\n";
    echo "Failures: " . ($failures === 0 ? "\033[32m0\033[0m" : "\033[31m$failures\033[0m") . "\n";
});

$touchedFiles = [
    'app/constant/reports/pos_user_sales_report.php',
    'app/constant/reports/pos_profit_report.php',
    'api/account/get_user_sales_report.php',
    'api/account/get_profit_report.php',
    'migrations/tenant/2026_09_17_pos_reports_by_user_and_profit.php',
];

// ─────────────────────────────────────────────────────────────────────────
section('1. All touched files lint-clean');
// ─────────────────────────────────────────────────────────────────────────
foreach ($touchedFiles as $f) {
    $rc = 0; $o = [];
    exec("php -l " . escapeshellarg("$root/$f") . " 2>&1", $o, $rc);
    $rc === 0 ? pass("$f lint-clean") : fail("$f lint failed: " . implode(' ', $o));
}

// ─────────────────────────────────────────────────────────────────────────
section('2. Source wiring');
// ─────────────────────────────────────────────────────────────────────────
$userPageSrc = file_get_contents("$root/app/constant/reports/pos_user_sales_report.php");
strpos($userPageSrc, "autoEnforcePermission('pos_user_sales_report')") !== false
    ? pass('Sales-by-User page enforces its own page_key') : fail('Sales-by-User page does not call autoEnforcePermission(pos_user_sales_report)');
strpos($userPageSrc, "canView('pos')") !== false
    ? pass('Sales-by-User page also checks canView(pos)') : fail('Sales-by-User page never checks canView(pos)');
strpos($userPageSrc, 'posSimpleModeEnabled()') !== false
    ? pass('Sales-by-User page checks posSimpleModeEnabled()') : fail('Sales-by-User page never checks posSimpleModeEnabled()');

$profitPageSrc = file_get_contents("$root/app/constant/reports/pos_profit_report.php");
strpos($profitPageSrc, "autoEnforcePermission('pos_profit_report')") !== false
    ? pass('Profit Report page enforces its own page_key') : fail('Profit Report page does not call autoEnforcePermission(pos_profit_report)');
strpos($profitPageSrc, "canView('pos')") !== false
    ? pass('Profit Report page also checks canView(pos)') : fail('Profit Report page never checks canView(pos)');
strpos($profitPageSrc, 'posSimpleModeEnabled()') !== false
    ? pass('Profit Report page checks posSimpleModeEnabled()') : fail('Profit Report page never checks posSimpleModeEnabled()');

$userApiSrc = file_get_contents("$root/api/account/get_user_sales_report.php");
strpos($userApiSrc, "scopeFilterSqlNullable('project', 'ps')") !== false
    ? pass('Sales-by-User API applies default project scope') : fail('Sales-by-User API missing default project scope');
strpos($userApiSrc, "scopeFilterSqlNullable('warehouse', 'ps')") !== false
    ? pass('Sales-by-User API applies default warehouse scope') : fail('Sales-by-User API missing default warehouse scope');
strpos($userApiSrc, "userCan('warehouse', \$warehouse_id)") !== false
    ? pass('Sales-by-User API scope-checks a submitted warehouse_id') : fail('Sales-by-User API never verifies warehouse_id is in scope');
strpos($userApiSrc, "sale_status = 'completed'") !== false
    ? pass('Sales-by-User API only counts completed POS sales') : fail('Sales-by-User API does not filter to completed sales');

$profitApiSrc = file_get_contents("$root/api/account/get_profit_report.php");
strpos($profitApiSrc, 'core/financial_reports.php') !== false && strpos($profitApiSrc, 'glProfitLoss(') !== false
    ? pass('Profit Report API derives figures from glProfitLoss() — the one ledger') : fail('Profit Report API does not call glProfitLoss()');
strpos($profitApiSrc, 'FROM pos_sales') === false && strpos($profitApiSrc, 'FROM expenses') === false
    ? pass('Profit Report API has no direct pos_sales/expenses reads (ledger-only, per reporting-source.md)')
    : fail('Profit Report API reads raw POS/expense tables directly — violates the one-ledger rule');
strpos($profitApiSrc, "userCan('warehouse', \$warehouse_id)") !== false
    ? pass('Profit Report API scope-checks a submitted warehouse_id') : fail('Profit Report API never verifies warehouse_id is in scope');

// ─────────────────────────────────────────────────────────────────────────
section('3. Live (in-process, admin session)');
// ─────────────────────────────────────────────────────────────────────────
$adminUid = (int)($pdo->query("SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = $adminUid;
$_SESSION['is_admin'] = true;
$_SESSION['role_id']  = 1;
$_SESSION['scope']    = ['is_admin' => true];

function _call_report(string $root, string $file, array $get): array {
    $_GET = $get;
    ob_start();
    require "$root/$file";
    $out = ob_get_clean();
    $json = json_decode($out, true);
    return is_array($json) ? $json : ['success' => false, 'raw' => $out];
}

$origSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
function _set_simple_mode($pdo, $on) {
    $val = $on ? '1' : '0';
    $exists = $pdo->query("SELECT 1 FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    if ($exists) {
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$val]);
    } else {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode', ?)")->execute([$val]);
    }
}

try {
    _set_simple_mode($pdo, true);

    // ── Sales by User: totals reconcile against real completed POS sales ──
    $wide = ['date_from' => '2000-01-01', 'date_to' => '2035-12-31'];
    $resp = _call_report($root, 'api/account/get_user_sales_report.php', $wide);
    $resp['success'] === true ? pass('Sales-by-User: response success=true') : fail('Sales-by-User: response failed — ' . json_encode($resp));

    $expectedTotal = (float)($pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales WHERE sale_status='completed'")->fetchColumn());
    $expectedCount = (int)($pdo->query("SELECT COUNT(*) FROM pos_sales WHERE sale_status='completed'")->fetchColumn());

    $rowsSum = 0.0; $rowsCount = 0;
    foreach (($resp['rows'] ?? []) as $r) { $rowsSum += (float)$r['total_value']; $rowsCount += (int)$r['sales_count']; }

    abs($rowsSum - $expectedTotal) < 0.01
        ? pass("per-cashier rows sum to the real completed-sales total (" . number_format($expectedTotal, 2) . ")")
        : fail("per-cashier rows summed to $rowsSum, expected $expectedTotal");
    $rowsCount === $expectedCount
        ? pass("per-cashier transaction counts sum to $expectedCount")
        : fail("per-cashier transaction counts summed to $rowsCount, expected $expectedCount");
    abs(($resp['summary']['total_value'] ?? -1) - $expectedTotal) < 0.01
        ? pass('summary.total_value matches the real completed-sales total')
        : fail('summary.total_value does not match');

    if (!empty($resp['rows'])) {
        $firstUser = (int)$resp['rows'][0]['user_id'];
        $itemsResp = _call_report($root, 'api/account/get_user_sales_report.php', $wide + ['mode' => 'items', 'user_id' => $firstUser]);
        $itemsResp['success'] === true ? pass('Sales-by-User items drill-down: response success=true') : fail('items drill-down failed: ' . json_encode($itemsResp));
        !empty($itemsResp['items']) ? pass('items drill-down returns product rows for a real cashier') : fail('items drill-down returned no rows for a cashier known to have sales');
    } else {
        fail('no per-cashier rows returned — cannot test the items drill-down (does this DB have completed POS sales?)');
    }

    // ── Profit Report: must match glProfitLoss() exactly, same params ─────
    $profResp = _call_report($root, 'api/account/get_profit_report.php', $wide);
    if ($profResp['success'] === true) {
        pass('Profit Report: response success=true');
        $direct = glProfitLoss($pdo, $wide['date_from'], $wide['date_to'], null, '', null);
        foreach (['total_revenue', 'total_cogs', 'gross_profit', 'total_expense', 'net_profit'] as $k) {
            $apiVal = (float)($profResp['summary'][$k] ?? NAN);
            $directVal = (float)($direct[$k] ?? NAN);
            abs($apiVal - $directVal) < 0.01
                ? pass("summary.$k matches glProfitLoss() directly ($directVal)")
                : fail("summary.$k = $apiVal, glProfitLoss() = $directVal — API diverges from the ledger engine");
        }
        $rev = (float)($profResp['summary']['total_revenue'] ?? 0);
        $np  = (float)($profResp['summary']['net_profit'] ?? 0);
        $expectedMargin = $rev > 0.001 ? round(($np / $rev) * 100, 1) : 0.0;
        abs(($profResp['summary']['net_margin_pct'] ?? -999) - $expectedMargin) < 0.11
            ? pass('net_margin_pct = net_profit / revenue * 100') : fail('net_margin_pct formula wrong');
        count($profResp['trend'] ?? []) <= 24
            ? pass('monthly trend capped at 24 points (' . count($profResp['trend']) . ' returned)')
            : fail('monthly trend exceeded the 24-point cap: ' . count($profResp['trend']));
        !empty($profResp['trend']) ? pass('monthly trend is non-empty for a range with real ledger activity') : fail('monthly trend is empty');
    } else {
        fail('Profit Report endpoint failed: ' . json_encode($profResp) . ' (is the account-type classification installed? see fc_classification_ready())');
    }

    // ── Simple Mode OFF → both report "not available", not a 500 ──────────
    // get_setting() (helpers.php) caches EVERY system_settings row in a
    // function-local `static` the first time anything in this process calls
    // it (roots.php's own bootstrap already has, by this point) — so
    // toggling pos_simple_mode mid-process and re-calling the API in-process
    // would silently keep reading the stale cached value (verified: a fresh
    // `php -r` process picks up a DB-side flip correctly, this process does
    // not). A real subprocess is the only way to observe an "OFF" read.
    _set_simple_mode($pdo, false);
    $probe = <<<'PHP'
        $_SERVER['argv'] ??= [];
        require $argv[1];
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['user_id'] = (int)$argv[2];
        $_SESSION['is_admin'] = true;
        $_SESSION['role_id'] = 1;
        $_GET = ['date_from' => $argv[3], 'date_to' => $argv[4]];
        require $argv[5];
        PHP;
    $probeFile = tempnam(sys_get_temp_dir(), 'pos_report_probe_') . '.php';
    file_put_contents($probeFile, "<?php\n" . $probe . "\n");
    foreach ([
        'Sales-by-User' => 'api/account/get_user_sales_report.php',
        'Profit Report' => 'api/account/get_profit_report.php',
    ] as $label => $apiRel) {
        $cmd = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, $probeFile, "$root/roots.php", (string)$adminUid, $wide['date_from'], $wide['date_to'], "$root/$apiRel",
        ]));
        $out = shell_exec($cmd);
        $json = json_decode(trim((string)$out), true);
        (is_array($json) && $json['success'] === false)
            ? pass("Simple Mode OFF (fresh process): $label API refuses cleanly")
            : fail("Simple Mode OFF (fresh process): $label API should refuse but returned: " . substr((string)$out, 0, 200));
    }
    @unlink($probeFile);
    _set_simple_mode($pdo, true);

    // ── Non-admin, out-of-scope warehouse_id → 403 on both APIs ────────────
    $warehouseIds = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' ORDER BY warehouse_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    if (count($warehouseIds) >= 1) {
        $inScope    = (int)$warehouseIds[0];
        $outOfScope = isset($warehouseIds[1]) ? (int)$warehouseIds[1] : $inScope + 900001;

        $_SESSION['is_admin'] = false;
        $_SESSION['scope']    = ['is_admin' => false, 'warehouses' => [$inScope], 'projects' => []];

        $deniedUser = _call_report($root, 'api/account/get_user_sales_report.php', $wide + ['warehouse_id' => $outOfScope]);
        $deniedUser['success'] === false ? pass('Sales-by-User: non-admin denied an out-of-scope warehouse_id') : fail('Sales-by-User: non-admin was NOT denied an out-of-scope warehouse_id — scope leak');

        $deniedProfit = _call_report($root, 'api/account/get_profit_report.php', $wide + ['warehouse_id' => $outOfScope]);
        $deniedProfit['success'] === false ? pass('Profit Report: non-admin denied an out-of-scope warehouse_id') : fail('Profit Report: non-admin was NOT denied an out-of-scope warehouse_id — scope leak');

        $_SESSION['is_admin'] = true;
        $_SESSION['scope']    = ['is_admin' => true];
    } else {
        fail('no active warehouse exists in this database — skipping the out-of-scope warehouse check');
    }
} finally {
    if ($origSetting === false) {
        $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    } else {
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSetting]);
    }
    pass('pos_simple_mode setting restored to its original value');
}

exit($failures === 0 ? 0 : 1);
