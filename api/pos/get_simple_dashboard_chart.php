<?php
// scope-audit: skip — reads pos_sales via scopeFilterSqlNullable('project','ps') / ('warehouse','ps') below
/**
 * API: Simple Mode dashboard chart — Bought vs Sold.
 * ----------------------------------------------------------------------------
 * Feeds app/dashboard.php's Performance Overview chart when a tenant has
 * turned on POS Settings > Simple Mode (core/pos_nav.php::posSimpleModeEnabled()).
 * Deliberately reads pos_sales/pos_sale_items + products.cost_price, never
 * the ledger — see core/pos_dashboard_metrics.php::posSimpleBuySellSeries().
 *
 * Response shape intentionally matches api/get_performance_data.php's
 * {period, revenue, expense} contract so app/dashboard.php's existing
 * renderChart() can consume either endpoint unchanged: revenue = sold,
 * expense = bought (cost). Only the on-screen labels differ (set in
 * app/dashboard.php based on posSimpleModeEnabled()).
 *
 * GET: period = daily|weekly|monthly|quarterly|yearly (default monthly)
 * Permission: canView('pos')
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/permissions.php';   // loads core/project_scope.php
require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_dashboard_metrics.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

$period = $_GET['period'] ?? 'monthly';
if (!in_array($period, ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'], true)) {
    $period = 'monthly';
}

try {
    global $pdo;

    $endDate = date('Y-m-d');
    switch ($period) {
        case 'daily':     $startDate = date('Y-m-d', strtotime('-29 days'));  break;
        case 'weekly':    $startDate = date('Y-m-d', strtotime('-12 weeks')); break;
        case 'quarterly': $startDate = date('Y-m-d', strtotime('-2 years'));  break;
        case 'yearly':    $startDate = date('Y-m-d', strtotime('-10 years')); break;
        default:          $startDate = date('Y-m-d', strtotime('-11 months'));break;
    }

    $scope = scopeFilterSqlNullable('project', 'ps')
           . scopeFilterSqlNullable('warehouse', 'ps');

    $rows = posSimpleBuySellSeries($pdo, $startDate, $endDate, $period, $scope);

    $data = [];
    foreach ($rows as $row) {
        $label = $row['period'];
        if ($period === 'daily') {
            $label = date('M d', strtotime($row['period']));
        } elseif ($period === 'monthly') {
            $label = date('M Y', strtotime($row['period'] . '-01'));
        } elseif ($period === 'weekly') {
            $parts = explode('-', $row['period']);
            $label = (count($parts) === 2) ? "Wk {$parts[1]}, {$parts[0]}" : "Wk " . $row['period'];
        }
        $data[] = [
            'period'  => $label,
            'revenue' => $row['sold'],    // "Sold" — displayed label set client-side
            'expense' => $row['bought'],  // "Bought" — displayed label set client-side
            'profit'  => $row['profit'],
        ];
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    error_log("Simple Dashboard Chart API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
