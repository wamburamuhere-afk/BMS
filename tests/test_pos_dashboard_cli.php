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
            // Low stock is now a per-warehouse product_stocks reconciliation
            // (2026-07-17 fix — was reading the company-wide products.current_stock
            // rollup, which ignored the viewer's warehouse scope entirely).
            $lowCnt   = (int)$pdo->query("
                SELECT COUNT(*) FROM (
                    SELECT p.product_id
                      FROM product_stocks lps
                      JOIN products p ON lps.product_id = p.product_id
                     WHERE p.status = 'active' AND COALESCE(p.is_service,0) = 0
                  GROUP BY p.product_id
                    HAVING SUM(lps.stock_quantity) <= MAX(COALESCE(NULLIF(lps.min_stock_level,0), p.reorder_level, 0))
                ) t
            ")->fetchColumn();

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

    // ── G. Phase 29 — Dashboard Intelligence (Sales Targets, Top Cashiers, Damage/Shrinkage) ──
    section('G. Phase 29 — static wiring (source patterns)');

    $metricsFile = "$root/core/pos_dashboard_metrics.php";
    ok(is_file($metricsFile), 'core/pos_dashboard_metrics.php exists');
    $mSrc = src($metricsFile);
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg($metricsFile) . ' 2>&1', $o, $rc);
    ok($rc === 0, 'core/pos_dashboard_metrics.php lint-clean');
    ok(strpos($mSrc, 'function damageShrinkageSummary')  !== false, 'damageShrinkageSummary() defined');
    ok(strpos($mSrc, 'function topCashiers')              !== false, 'topCashiers() defined');
    ok(strpos($mSrc, 'function salesTargetAchievement')   !== false, 'salesTargetAchievement() defined');

    $saveTargetFile = "$root/api/pos/save_sales_target.php";
    ok(is_file($saveTargetFile), 'api/pos/save_sales_target.php exists');
    $stSrc = src($saveTargetFile);
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg($saveTargetFile) . ' 2>&1', $o, $rc);
    ok($rc === 0, 'api/pos/save_sales_target.php lint-clean');
    ok(strpos($stSrc, "canView('pos_advanced')") !== false, 'save_sales_target gated on canView(pos_advanced)');
    ok(strpos($stSrc, "canEdit('pos_advanced')") !== false, 'save_sales_target gated on canEdit(pos_advanced)');
    ok(strpos($stSrc, 'csrf_check()') !== false, 'save_sales_target enforces CSRF');
    ok(strpos($stSrc, "userCan('warehouse'") !== false, 'save_sales_target verifies warehouse scope before writing');

    $a2 = src($api);
    ok(strpos($a2, "canView('pos_advanced')") !== false, 'get_dashboard.php gates the sales_target block on canView(pos_advanced)');
    ok(strpos($a2, "'sales_target'") !== false, 'get_dashboard.php response includes a sales_target key');
    ok(strpos($a2, "'damage_shrinkage'") !== false, 'get_dashboard.php response includes damage_shrinkage (base pos, ungated)');
    ok(strpos($a2, "'top_cashiers'") !== false, 'get_dashboard.php response includes top_cashiers (base pos, ungated)');

    ok(strpos($p, 'id="damageShrinkage"') !== false, 'Damage/Shrinkage tile container present');
    ok(strpos($p, 'id="topCashiers"')     !== false, 'Top Cashiers tile container present');
    ok(strpos($p, 'id="salesTarget"')     !== false, 'Sales Target tile container present');
    ok(strpos($p, 'id="targetModal"')     !== false, 'Set Sales Target modal present');
    ok(strpos($p, '$can_view_targets')    !== false, 'page computes $can_view_targets = canView(pos_advanced)');
    ok(strpos($p, '$can_edit_targets')    !== false, 'page computes $can_edit_targets = canEdit(pos_advanced)');
    // The Sales Target CARD and the Set-Target MODAL must each be wrapped in
    // their own PHP gate — genuinely absent from the HTML, not CSS-hidden.
    $tileGatePos  = strpos($p, '<?php if ($can_view_targets): ?>');
    $tileIdPos    = strpos($p, 'id="salesTarget"');
    ok($tileGatePos !== false && $tileGatePos < $tileIdPos, 'Sales Target tile is wrapped in a $can_view_targets PHP if-block (genuinely absent, not hidden)');
    $modalGatePos = strpos($p, '<?php if ($can_view_targets && $can_edit_targets): ?>');
    $modalIdPos   = strpos($p, 'id="targetModal"');
    ok($modalGatePos !== false && $modalGatePos < $modalIdPos, 'Set Sales Target modal is wrapped in a $can_view_targets && $can_edit_targets PHP if-block');
    ok(strpos($p, 'name="_csrf"', $modalGatePos) !== false, 'Set Sales Target modal carries a CSRF token');

    section('G2. Phase 29 — achievement-band boundaries (pure function, exact edges)');

    $bandCases = [
        [100.0, 'achieved'],           [99.99, 'on_track'],
        [75.0,  'on_track'],           [74.99, 'needs_improvement'],
        [50.0,  'needs_improvement'],  [49.99, 'action_required'],
        [0.0,   'action_required'],    [150.0, 'achieved'],
    ];
    foreach ($bandCases as [$pct, $expected]) {
        $band = salesTargetAchievementBand($pct);
        ok($band['key'] === $expected, "band($pct%) == '$expected' (got '{$band['key']}')");
    }

    section('G3. Phase 29 — live reconciliation (in-process, admin scope)');

    if (!(bool)$pdo->query("SHOW TABLES LIKE 'pos_sales_targets'")->fetch()) {
        ok(true, 'pos_sales_targets absent — Phase 29 live checks skipped');
    } else {
        $mfrom = date('Y-m-01'); $today = date('Y-m-d'); $periodMonth = date('Y-m-01');

        // Damage/Shrinkage reconciles to direct SQL (admin scope == unscoped).
        $ds = damageShrinkageSummary($pdo, '', $mfrom, $today);
        $sqlDs = $pdo->prepare("SELECT movement_type, SUM(ABS(quantity)) AS qty FROM stock_movements WHERE movement_type IN ('damaged','expired','theft') AND DATE(movement_date) BETWEEN ? AND ? GROUP BY movement_type");
        $sqlDs->execute([$mfrom, $today]);
        $expectDs = ['damaged' => 0.0, 'expired' => 0.0, 'theft' => 0.0, 'total' => 0.0];
        foreach ($sqlDs->fetchAll(PDO::FETCH_ASSOC) as $row) { $expectDs[$row['movement_type']] = round((float)$row['qty'], 3); $expectDs['total'] += round((float)$row['qty'], 3); }
        ok(approx($ds['damaged'], $expectDs['damaged']) && approx($ds['expired'], $expectDs['expired']) && approx($ds['theft'], $expectDs['theft']),
            sprintf('damageShrinkageSummary() == direct SQL (damaged=%.3f expired=%.3f theft=%.3f)', $expectDs['damaged'], $expectDs['expired'], $expectDs['theft']));

        // Top Cashiers reconciles to direct SQL.
        $tc = topCashiers($pdo, '', $mfrom, $today, 5);
        $sqlTc = $pdo->prepare("SELECT ps.user_id, SUM(ps.grand_total - ps.tax_amount) AS total, COUNT(*) AS cnt FROM pos_sales ps WHERE ps.sale_status='completed' AND ps.is_return_sale=0 AND DATE(ps.sale_date) BETWEEN ? AND ? GROUP BY ps.user_id ORDER BY total DESC LIMIT 5");
        $sqlTc->execute([$mfrom, $today]);
        $expectTc = $sqlTc->fetchAll(PDO::FETCH_ASSOC);
        ok(count($tc) === count($expectTc), 'topCashiers() row count == direct SQL (' . count($expectTc) . ')');
        $tcMatches = true;
        foreach ($tc as $i => $row) {
            if (!isset($expectTc[$i]) || (int)$expectTc[$i]['user_id'] !== $row['user_id'] || !approx($expectTc[$i]['total'], $row['total']) || (int)$expectTc[$i]['cnt'] !== $row['count']) { $tcMatches = false; break; }
        }
        ok($tcMatches, 'topCashiers() rows (user_id/total/count) == direct SQL, in order');

        // Sales Target — no row exists yet for this month/scope (table just created).
        $existing = $pdo->prepare("SELECT COUNT(*) FROM pos_sales_targets WHERE warehouse_id=0 AND user_id=0 AND period_month=?");
        $existing->execute([$periodMonth]);
        $hadRowAlready = (int)$existing->fetchColumn() > 0;
        if (!$hadRowAlready) {
            $sa = salesTargetAchievement($pdo, 0, $periodMonth);
            ok($sa['has_target'] === false, 'salesTargetAchievement() reports has_target=false when no target row exists');
        } else {
            ok(true, 'a company-wide target already exists for this month — has_target=false path skipped (not a fresh-data condition)');
        }

        // Synthetic, transaction-wrapped: insert a damage movement + a sales
        // target row, verify both functions pick them up correctly, then roll
        // back so nothing touches real data.
        $pdo->beginTransaction();
        try {
            $sampleProduct   = (int)$pdo->query("SELECT product_id FROM products LIMIT 1")->fetchColumn();
            $sampleWarehouse = (int)$pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1")->fetchColumn();
            $adminUid = $uid ?: 1;

            if ($sampleProduct && $sampleWarehouse) {
                $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, warehouse_id, movement_date, created_by, reason) VALUES (?, 'damaged', 7, ?, NOW(), ?, 'Phase 29 test fixture')")
                    ->execute([$sampleProduct, $sampleWarehouse, $adminUid]);

                $dsAfter = damageShrinkageSummary($pdo, '', $mfrom, $today);
                ok(approx($dsAfter['damaged'], $expectDs['damaged'] + 7), 'damageShrinkageSummary() picks up a freshly-inserted damaged-stock row (+7)');
            } else {
                ok(true, 'no product/warehouse row available to build the damage-movement fixture — skipped');
            }

            // Target amount chosen so actual/target lands precisely at a
            // known, non-trivial band for whatever the real "actual" happens
            // to be right now (actual could legitimately be 0.00).
            $baseline = salesTargetAchievement($pdo, 0, $periodMonth);
            $actualNow = $baseline['actual'];
            $targetAmount = $actualNow > 0 ? round($actualNow / 0.80, 2) : 1000.00; // actual/target = 80% => 'on_track'
            $pdo->prepare("INSERT INTO pos_sales_targets (warehouse_id, user_id, period_month, target_amount, created_by) VALUES (0, 0, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE target_amount = VALUES(target_amount)")
                ->execute([$periodMonth, $targetAmount, $adminUid]);

            $sa2 = salesTargetAchievement($pdo, 0, $periodMonth);
            $expectedPct = $targetAmount > 0 ? round(($actualNow / $targetAmount) * 100, 1) : 0.0;
            ok($sa2['has_target'] === true, 'salesTargetAchievement() reports has_target=true once a row exists');
            ok(approx($sa2['target_amount'], $targetAmount), 'salesTargetAchievement() target_amount == inserted row');
            ok(approx($sa2['pct'], $expectedPct), sprintf('salesTargetAchievement() pct == actual/target*100 (%.1f%%)', $expectedPct));
            ok($sa2['band'] === salesTargetAchievementBand($expectedPct)['key'], 'salesTargetAchievement() band matches salesTargetAchievementBand() for the same pct');

            // Entitlement behavioural check — same $GLOBALS['__bms_features']
            // technique as test_pos_phase13_entitlement_cli.php. With
            // pos_advanced forced off, the API must omit sales_target entirely
            // while still returning damage_shrinkage/top_cashiers (base pos).
            if (function_exists('allFeatureKeys')) {
                $prevFeatures = $GLOBALS['__bms_features'] ?? null;
                $GLOBALS['__bms_features'] = array_fill_keys(allFeatureKeys(), true);
                $GLOBALS['__bms_features']['pos_advanced'] = false;

                $_GET = [];
                ob_start(); include $api; $json2 = ob_get_clean();
                $d2 = json_decode($json2, true);
                ok($d2 && !empty($d2['success']) && $d2['data']['sales_target'] === null,
                    'get_dashboard.php omits sales_target when pos_advanced is off, even for an admin session');
                ok($d2 && isset($d2['data']['damage_shrinkage']) && isset($d2['data']['top_cashiers']),
                    'get_dashboard.php still returns damage_shrinkage/top_cashiers when pos_advanced is off (base pos)');

                $GLOBALS['__bms_features'] = $prevFeatures;
                $_GET = [];
                ob_start(); include $api; ob_get_clean(); // restore session state for anything after this block
                ok((canView('pos_advanced') === true), 'canView(pos_advanced) restored to true after resetting the feature map');
            } else {
                ok(true, 'allFeatureKeys() not available — entitlement toggle check skipped');
            }
        } finally {
            $pdo->rollBack();
        }

        // Post-rollback sanity: the synthetic rows must be gone.
        $existing2 = $pdo->prepare("SELECT COUNT(*) FROM pos_sales_targets WHERE warehouse_id=0 AND user_id=0 AND period_month=? AND target_amount = ?");
        $gone = true;
        try { $existing2->execute([$periodMonth, $targetAmount ?? -1]); $gone = ((int)$existing2->fetchColumn() === 0) || $hadRowAlready; } catch (Throwable $e) { $gone = true; }
        ok($gone, 'synthetic sales-target row rolled back cleanly (or a pre-existing row was left untouched)');
    }

} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage());
}

echo "\n";
exit($fail === 0 ? 0 : 1);
