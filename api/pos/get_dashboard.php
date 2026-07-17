<?php
// scope-audit: skip — POS dashboard reads pos_sales via scopeFilterSqlNullable('project','ps') below
/**
 * API: POS Dashboard metrics
 * ----------------------------------------------------------------------------
 * Read-only analytics for app/bms/pos/pos_dashboard.php. Project-scoped per §23.
 * Every tile reconciles to a direct SQL aggregate (guarded by test_pos_dashboard_cli).
 *
 * Recognised POS sale = sale_status IN (completed,partially_refunded,refunded),
 * is_return_sale = 0, invoice_id IS NULL. Net revenue = grand_total − tax_amount,
 * less recognised POS returns (is_return_sale = 1) — same model as the P&L.
 *
 * GET (optional): trend_days (default 14)
 * Permission: canView('pos')
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';   // loads core/project_scope.php

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('pos'))    { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$trend_days = (int)($_GET['trend_days'] ?? 14);
if ($trend_days < 1 || $trend_days > 90) $trend_days = 14;

try {
    global $pdo;
    $scope = scopeFilterSqlNullable('project', 'ps')    // '' for admins; scoped for others
           . scopeFilterSqlNullable('warehouse', 'ps');

    $today      = date('Y-m-d');
    $month_from = date('Y-m-01');
    $trend_from = date('Y-m-d', strtotime("-" . ($trend_days - 1) . " days"));

    // Recognition predicates (mirror the Income Statement).
    $recOrig = "ps.sale_status IN ('completed','partially_refunded','refunded') AND ps.is_return_sale = 0 AND ps.invoice_id IS NULL";
    $recRet  = "ps.is_return_sale = 1 AND ps.sale_status NOT IN ('voided','cancelled') AND ps.invoice_id IS NULL";

    // Net revenue + sale count for a date window (gross originals − returns).
    $window = function (string $from, string $to) use ($pdo, $scope, $recOrig, $recRet) {
        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN $recOrig THEN ps.grand_total - ps.tax_amount
                                      WHEN $recRet  THEN -(ps.grand_total - ps.tax_amount) ELSE 0 END), 0) AS net,
                    SUM(CASE WHEN $recOrig THEN 1 ELSE 0 END) AS cnt
                  FROM pos_sales ps
                 WHERE DATE(ps.sale_date) BETWEEN ? AND ? $scope";
        $st = $pdo->prepare($sql); $st->execute([$from, $to]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['net' => 0, 'cnt' => 0];
        $net = (float)$r['net']; $cnt = (int)$r['cnt'];
        return ['net' => round($net, 2), 'count' => $cnt, 'aov' => $cnt > 0 ? round($net / $cnt, 2) : 0.0];
    };

    $todayStats = $window($today, $today);
    $monthStats = $window($month_from, $today);

    // Items sold today (recognised originals only).
    $itemsSql = "SELECT COALESCE(SUM(psi.quantity), 0)
                   FROM pos_sale_items psi
                   JOIN pos_sales ps ON ps.sale_id = psi.sale_id
                  WHERE DATE(ps.sale_date) = ? AND $recOrig $scope";
    $st = $pdo->prepare($itemsSql); $st->execute([$today]);
    $items_today = (float)$st->fetchColumn();

    // Daily net-sales trend.
    $trendSql = "SELECT DATE(ps.sale_date) AS d,
                        COALESCE(SUM(CASE WHEN $recOrig THEN ps.grand_total - ps.tax_amount
                                          WHEN $recRet  THEN -(ps.grand_total - ps.tax_amount) ELSE 0 END), 0) AS net
                   FROM pos_sales ps
                  WHERE DATE(ps.sale_date) BETWEEN ? AND ? $scope
               GROUP BY DATE(ps.sale_date)";
    $st = $pdo->prepare($trendSql); $st->execute([$trend_from, $today]);
    $byDay = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $byDay[$row['d']] = (float)$row['net'];
    $trend = [];
    for ($i = $trend_days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $trend[] = ['date' => $d, 'label' => date('d M', strtotime($d)), 'net' => round($byDay[$d] ?? 0, 2)];
    }

    // Top products this month (by qty), recognised originals.
    $topSql = "SELECT COALESCE(p.product_name, psi.product_name) AS name,
                      SUM(psi.quantity) AS qty,
                      SUM(psi.line_total) AS revenue
                 FROM pos_sale_items psi
                 JOIN pos_sales ps  ON ps.sale_id = psi.sale_id
            LEFT JOIN products p     ON p.product_id = psi.product_id
                WHERE DATE(ps.sale_date) BETWEEN ? AND ? AND $recOrig $scope
             GROUP BY name
             ORDER BY qty DESC
                LIMIT 5";
    $st = $pdo->prepare($topSql); $st->execute([$month_from, $today]);
    $top_products = array_map(fn($r) => [
        'name' => $r['name'] ?: '—', 'qty' => (float)$r['qty'], 'revenue' => round((float)$r['revenue'], 2),
    ], $st->fetchAll(PDO::FETCH_ASSOC));

    // Low stock — per-warehouse balance from product_stocks, scoped by the
    // viewer's warehouse access (product_stocks carries no project_id of its
    // own, so warehouse scope applies there) AND by the product's own
    // project tag on the products row — not the company-wide
    // products.current_stock rollup this used to read.
    $lowStockScope = scopeFilterSqlNullable('warehouse', 'lps') . scopeFilterSqlNullable('project', 'p');
    $lowStockBase = "
        FROM product_stocks lps
        JOIN products p ON lps.product_id = p.product_id
       WHERE p.status = 'active' AND COALESCE(p.is_service,0) = 0 $lowStockScope
    ";
    $lowStockHaving = "HAVING SUM(lps.stock_quantity) <= MAX(COALESCE(NULLIF(lps.min_stock_level,0), p.reorder_level, 0))";

    $low_count = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT p.product_id $lowStockBase GROUP BY p.product_id $lowStockHaving
        ) t
    ")->fetchColumn();

    $low_stock = $pdo->query("
        SELECT p.product_name,
               SUM(lps.stock_quantity) AS current_stock,
               MAX(COALESCE(NULLIF(lps.min_stock_level,0), p.reorder_level, 0)) AS min_stock_level
        $lowStockBase
        GROUP BY p.product_id, p.product_name
        $lowStockHaving
        ORDER BY (SUM(lps.stock_quantity) - MAX(COALESCE(NULLIF(lps.min_stock_level,0), p.reorder_level, 0))) ASC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
    $low_stock = array_map(fn($r) => [
        'name' => $r['product_name'], 'current' => (float)$r['current_stock'], 'min' => (float)$r['min_stock_level'],
    ], $low_stock);

    // Recent sales (incl. returns, marked).
    $recentSql = "SELECT ps.sale_id, ps.receipt_number, ps.sale_date, ps.grand_total, ps.sale_status,
                         ps.payment_status, ps.is_return_sale,
                         COALESCE(NULLIF(ps.customer_name,''), c.customer_name, c.company_name, 'Walk-in') AS party
                    FROM pos_sales ps
               LEFT JOIN customers c ON c.customer_id = ps.customer_id
                   WHERE 1=1 $scope
                ORDER BY ps.sale_date DESC, ps.sale_id DESC
                   LIMIT 8";
    $recent = array_map(fn($r) => [
        'sale_id'        => (int)$r['sale_id'],
        'receipt_number' => $r['receipt_number'],
        'sale_date'      => $r['sale_date'],
        'grand_total'    => (float)$r['grand_total'],
        'sale_status'    => $r['sale_status'],
        'payment_status' => $r['payment_status'],
        'is_return_sale' => (int)$r['is_return_sale'],
        'party'          => $r['party'],
    ], $pdo->query($recentSql)->fetchAll(PDO::FETCH_ASSOC));

    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed POS Dashboard');
    echo json_encode([
        'success' => true,
        'data' => [
            'today' => $todayStats + ['items_sold' => $items_today],
            'month' => $monthStats,
            'low_stock_count' => $low_count,
            'trend' => $trend,
            'top_products' => $top_products,
            'low_stock' => $low_stock,
            'recent' => $recent,
        ],
    ]);

} catch (Throwable $e) {
    error_log('get_dashboard: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
