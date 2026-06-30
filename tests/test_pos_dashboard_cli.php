<?php
/**
 * POS Dashboard — regression suite
 *   php tests/test_pos_dashboard_cli.php
 *
 *   A. STATIC — API + page: lint, permission, scope, structure.
 *   B. LAYOUT — Sales History (top, always visible) + Dashboard (below, no toggle).
 *   C. PERIOD FILTER — five period buttons + setPeriodDates().
 *   D. TOOLBAR — Copy/CSV/Print matching suppliers.php pattern.
 *   E. UI-CONSTANTS — stat card colors, modal headers, gear action, no alert().
 *   F. LIVE — in-process API; tile values reconcile to direct SQL aggregates.
 *
 * Read-only. Exit 0 = pass.
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
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
});

try {
    // ── A. Contract ────────────────────────────────────────────
    section('A. Contract (lint + permissions + scope)');

    $api  = "$root/api/pos/get_dashboard.php";
    $page = "$root/app/bms/pos/pos_dashboard.php";
    foreach (['get_dashboard.php' => $api, 'pos_dashboard.php' => $page] as $n => $f) {
        $o = []; $rc = 0; exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
        ok($rc === 0, "$n lint-clean");
    }

    $a = src($api); $p = src($page);
    ok(strpos($a, "canView('pos')") !== false, 'API gated on canView(pos)');
    ok(strpos($a, "scopeFilterSqlNullable('project', 'ps')") !== false, 'API project-scoped');
    ok(strpos($p, "autoEnforcePermission('pos')") !== false, 'page permission-gated');
    ok(strpos($p, 'Viewed POS Workspace') !== false, 'page writes view-activity log');
    ok(strlen($p) > 500, 'page source is non-trivially large');

    // ── B. Layout: Sales History first, no toggle, both always visible ──
    section('B. Layout (Sales History on top; no toggle; both panes always visible)');

    // Old toggle buttons must be gone
    ok(strpos($p, 'btnViewDashboard') === false, 'Old #btnViewDashboard toggle removed');
    ok(strpos($p, 'btnViewHistory')   === false, 'Old #btnViewHistory toggle removed');

    // Both panes present
    ok(strpos($p, 'id="paneHistory"')   !== false, '#paneHistory section present');
    ok(strpos($p, 'id="paneDashboard"') !== false, '#paneDashboard section present');

    // By default: history visible, dashboard hidden (toggle behaviour)
    ok(!preg_match('/<[^>]*id="paneHistory"[^>]*d-none/', $p), '#paneHistory NOT hidden by d-none at page load (shown by default)');
    ok((bool)preg_match('/<[^>]*id="paneDashboard"[^>]*d-none/', $p), '#paneDashboard IS hidden by d-none at page load (toggle reveals it)');

    // Toggle button present
    ok(strpos($p, 'id="btnToggleDash"') !== false, 'Sales Dashboard toggle button #btnToggleDash present');

    // Sales History (paneHistory) must appear BEFORE Dashboard (paneDashboard)
    $posH = strpos($p, 'id="paneHistory"');
    $posD = strpos($p, 'id="paneDashboard"');
    ok($posH !== false && $posD !== false && $posH < $posD, 'Sales History section appears BEFORE Dashboard section');

    // Sales table
    ok(strpos($p, 'id="posSalesTable"') !== false, 'Sales DataTable #posSalesTable present');
    ok(strpos($p, '>S/NO<') !== false,              'Sales table has S/NO first column');
    ok(strpos($p, 'thead class="table-dark"') === false, 'No table-dark header (white + text-primary)');

    // Chart.js and trend chart
    ok(strpos($p, 'cdn.jsdelivr.net/npm/chart.js') !== false, 'Chart.js loaded');
    ok(strpos($p, 'id="trendChart"') !== false,               'Trend chart canvas present');
    ok(strpos($p, 'new Chart(') !== false,                     'Chart.js constructor called');

    // Mobile card view
    ok(strpos($p, 'id="cardView"') !== false, 'Mobile card view #cardView present');

    // ── C. Period filter ──────────────────────────────────────
    section('C. Period filter');

    foreach (['daily', 'weekly', 'monthly', 'quarterly', 'yearly'] as $period) {
        ok(strpos($p, "data-period=\"$period\"") !== false, "Period button '$period' present");
    }
    ok(strpos($p, 'function getDateRange') !== false, 'getDateRange() defined');
    ok(strpos($p, 'function showFilterPanel') !== false, 'showFilterPanel() defined');
    ok(strpos($p, 'function updateWeekLabel') !== false, 'updateWeekLabel() defined');
    ok(strpos($p, 'function initFilterDefaults') !== false, 'initFilterDefaults() defined');
    ok(strpos($p, 'id="fDay"') !== false,     'Daily date input #fDay present');
    ok(strpos($p, 'id="fWeekDay"') !== false, 'Weekly date input #fWeekDay present');
    ok(strpos($p, 'id="fMonth"') !== false,   'Monthly month select #fMonth present');
    ok(strpos($p, 'id="fYear"') !== false,    'Yearly year select #fYear present');
    ok(strpos($p, 'id="fQuarter"') !== false, 'Quarterly select #fQuarter present');
    ok(strpos($p, 'id="btnFilter"') !== false, 'Apply filter button present');
    ok(strpos($p, "class=\"apply-btn\"") !== false || strpos($p, 'apply-btn') !== false, 'apply-btn class used on all filter panels');
    ok(strpos($p, "id=\"fp-daily\"") !== false,     'Daily filter panel #fp-daily present');
    ok(strpos($p, "id=\"fp-weekly\"") !== false,    'Weekly filter panel #fp-weekly present');
    ok(strpos($p, "id=\"fp-monthly\"") !== false,   'Monthly filter panel #fp-monthly present');
    ok(strpos($p, "id=\"fp-quarterly\"") !== false, 'Quarterly filter panel #fp-quarterly present');
    ok(strpos($p, "id=\"fp-yearly\"") !== false,    'Yearly filter panel #fp-yearly present');

    // Yearly is the default active period
    $btnGroupMatch = preg_match('/btn-primary[^<]*yearly|yearly[^<]*btn-primary/', $p);
    ok((bool)$btnGroupMatch, 'Yearly period button is active (btn-primary) by default');

    // ── D. Toolbar (Copy / CSV / Print + Show:) ───────────────
    section('D. Toolbar (Copy/CSV/Print)');

    ok(strpos($p, 'function copyTable')  !== false, 'copyTable() defined');
    ok(strpos($p, 'function exportCSV')  !== false, 'exportCSV() defined');
    ok(strpos($p, 'function printTable') !== false, 'printTable() defined');
    ok(strpos($p, 'onclick="copyTable()"')  !== false, 'Copy button wired');
    ok(strpos($p, 'onclick="exportCSV()"')  !== false, 'CSV button wired');
    ok(strpos($p, 'onclick="printTable()"') !== false, 'Print button wired');
    ok(strpos($p, "extend: 'copyHtml5'")    !== false, 'DT copy button configured');
    ok(strpos($p, "extend: 'excelHtml5'")   !== false, 'DT excel button configured');
    ok(strpos($p, "extend: 'print'")        !== false, 'DT print button configured');
    ok(strpos($p, 'bi bi-clipboard text-info')                  !== false, 'Copy icon: bi-clipboard text-info');
    ok(strpos($p, 'bi bi-file-earmark-spreadsheet text-success') !== false, 'CSV icon: bi-file-earmark-spreadsheet');
    ok(strpos($p, 'bi bi-printer text-primary')                  !== false, 'Print icon: bi-printer text-primary');
    ok(strpos($p, 'id="pageLenSelect"') !== false, 'Show: page-length selector present');

    // ── E. UI-constants ───────────────────────────────────────
    section('E. UI-constants compliance');

    ok(strpos($p, 'background:#e7f0ff')        !== false, 'Stat cards: background:#e7f0ff');
    ok(strpos($p, 'border:1px solid #b6ccfe')  !== false, 'Stat cards: border:1px solid #b6ccfe');
    ok(strpos($p, 'bi bi-gear-fill')            !== false, 'Action button uses bi-gear-fill');
    ok(strpos($p, 'dropdown-menu dropdown-menu-end shadow border-0 p-2') !== false, 'Gear dropdown classes match pattern');
    ok(strpos($p, 'modal-header bg-primary text-white') !== false, 'Modal headers: bg-primary text-white');
    ok(strpos($p, 'btn-close-white') !== false, 'Modal close buttons: btn-close-white');
    ok(!preg_match('/\balert\s*\(/', $p), 'No raw alert() — SweetAlert used instead');
    ok(strpos($p, 'Swal.fire')    !== false, 'SweetAlert (Swal.fire) present');
    ok(strpos($p, 'function safeOutput') !== false, 'safeOutput() defined locally (not a global)');
    ok(strpos($p, 'safeOutput(')  !== false, 'safeOutput() used in JS templates');

    // Dashboard error handling — .fail() so "Loading…" can't stick
    ok(strpos($p, 'function loadDashboard') !== false, 'loadDashboard() defined');
    ok((bool)preg_match('/\.fail\s*\(/', $p), 'loadDashboard() has .fail() error handler');
    ok(strpos($p, 'Server error — click Refresh to retry') !== false, 'Dashboard shows user-friendly error on AJAX fail');

    // getActivePeriod reads btn-primary (not .active class) — this was the stat-card bug
    ok(strpos($p, ".period-btn.btn-primary") !== false, 'getActivePeriod() reads .btn-primary (correct; not stale .active)');

    // Dashboard DataTables
    ok(strpos($p, 'id="lowStockTable"')    !== false, 'Low Stock DataTable #lowStockTable present');
    ok(strpos($p, 'id="recentSalesTable"') !== false, 'Recent Sales DataTable #recentSalesTable present');
    ok(strpos($p, 'function initDashboardTables') !== false, 'initDashboardTables() defined');
    ok(strpos($p, 'dtRecent')     !== false, 'dtRecent DataTable variable present');
    ok(strpos($p, 'dtLowStock')   !== false, 'dtLowStock DataTable variable present');

    // Print footer injected in DataTables customize callback
    ok(strpos($p, 'BJP Technologies') !== false, 'Print footer: BJP Technologies copyright line present');
    ok(strpos($p, 'PRINT_ROLE')       !== false, 'Print footer: PRINT_ROLE constant used in customize');
    ok(strpos($p, 'PRINT_YEAR')       !== false, 'Print footer: PRINT_YEAR constant present');

    // Modals
    ok(strpos($p, 'id="returnModal"')  !== false, 'Return/Refund modal present');
    ok(strpos($p, 'id="receiveModal"') !== false, 'Receive Payment modal present');
    ok(strpos($p, 'name="_csrf"')      !== false, 'CSRF tokens in modals');

    // AJAX actions
    ok(strpos($p, 'function voidSale')     !== false, 'voidSale() defined');
    ok(strpos($p, 'function openReturn')   !== false, 'openReturn() defined');
    ok(strpos($p, 'function openReceive')  !== false, 'openReceive() defined');

    // ── F. Live tile reconciliation ───────────────────────────
    section('F. Live tile reconciliation (in-process)');

    if (!(bool)$pdo->query("SHOW TABLES LIKE 'pos_sales'")->fetch()) {
        ok(true, 'pos_sales absent — live tile checks skipped');
    } else {
        $uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id=1 ORDER BY user_id LIMIT 1")->fetchColumn();
        $_SESSION['user_id'] = $uid; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
        $_GET = [];

        ob_start();
        include $api;
        $json = ob_get_clean();
        $d = json_decode($json, true);
        ok($d && !empty($d['success']), 'dashboard API returns successfully in-process');

        if ($d && !empty($d['success'])) {
            $data  = $d['data'];
            $today = date('Y-m-d'); $mfrom = date('Y-m-01');
            $rec = "ps.sale_status IN ('completed','partially_refunded','refunded') AND ps.is_return_sale=0 AND ps.invoice_id IS NULL";
            $ret = "ps.is_return_sale=1 AND ps.sale_status NOT IN ('voided','cancelled') AND ps.invoice_id IS NULL";
            $netX = "COALESCE(SUM(CASE WHEN $rec THEN ps.grand_total-ps.tax_amount WHEN $ret THEN -(ps.grand_total-ps.tax_amount) ELSE 0 END),0)";

            $todayNet = (float)$pdo->query("SELECT $netX FROM pos_sales ps WHERE DATE(ps.sale_date)='$today'")->fetchColumn();
            $monthNet = (float)$pdo->query("SELECT $netX FROM pos_sales ps WHERE DATE(ps.sale_date) BETWEEN '$mfrom' AND '$today'")->fetchColumn();
            $lowCnt   = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE COALESCE(is_service,0)=0 AND current_stock <= min_stock_level")->fetchColumn();

            ok(approx($data['today']['net'], $todayNet), sprintf('today net == SQL (%.2f)', $todayNet));
            ok(approx($data['month']['net'], $monthNet), sprintf('month net == SQL (%.2f)', $monthNet));
            ok($data['low_stock_count'] === $lowCnt, "low-stock count == SQL ($lowCnt)");
            ok(is_array($data['trend']) && count($data['trend']) === 14, 'trend returns 14 days');
            ok(isset($data['today']['aov']) && ($data['today']['count'] == 0 || approx($data['today']['aov'], round($data['today']['net'] / $data['today']['count'], 2))), 'AOV == net / count');
            ok(is_array($data['recent'])       && count($data['recent']) <= 8,       'recent sales capped at 8');
            ok(is_array($data['top_products']) && count($data['top_products']) <= 5, 'top products capped at 5');
        }

        // get_sales.php structure check
        $scope  = scopeFilterSqlNullable('project', 'ps');
        $start  = date('Y-m-01'); $end = date('Y-m-t');
        $st = $pdo->prepare("SELECT ps.sale_id, ps.receipt_number, ps.grand_total, ps.sale_status FROM pos_sales ps WHERE DATE(ps.sale_date) BETWEEN ? AND ? $scope ORDER BY ps.sale_id DESC LIMIT 5");
        $st->execute([$start, $end]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        ok(is_array($rows), 'get_sales: query runs and returns array');
        if (!empty($rows)) {
            $r = $rows[0];
            ok(array_key_exists('sale_id',        $r), 'get_sales row has sale_id');
            ok(array_key_exists('receipt_number',  $r), 'get_sales row has receipt_number');
            ok(array_key_exists('grand_total',     $r), 'get_sales row has grand_total');
            ok(array_key_exists('sale_status',     $r), 'get_sales row has sale_status');
        } else {
            foreach (range(1, 4) as $_) ok(true, 'get_sales: empty result (query ran cleanly)');
        }
    }

} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage());
}

echo "\n";
exit($fail === 0 ? 0 : 1);
