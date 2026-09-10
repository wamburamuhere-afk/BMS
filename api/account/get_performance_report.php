<?php
/**
 * api/account/get_performance_report.php
 *
 * AJAX data source for the Business Performance dashboard — summary KPIs,
 * three chart datasets, and a monthly breakdown table as JSON.
 *
 *   Revenue       = invoices.grand_total + pos_sales.grand_total (actual
 *                   realised sales — same definition as get_sales_report.php,
 *                   the reference implementation; sales_orders is deliberately
 *                   NOT summed here too, since an order that gets invoiced
 *                   would otherwise be counted twice)
 *   Direct Costs  = purchase_orders.grand_total (COGS proxy)
 *   Expenses      = expenses.amount
 *   Gross Profit  = Revenue - Direct Costs
 *   Net Profit    = Gross Profit - Expenses
 *
 * 2026-09-10: Revenue used to be sales_orders.grand_total only, so a POS-only
 * tenant (Sales module off) always saw zero revenue here even with real POS
 * activity. Fixed by switching to the same invoices+pos_sales UNION ALL
 * get_sales_report.php already uses, and adding the same warehouse_id filter
 * (Direct Costs too, since purchase_orders carries warehouse_id).
 *
 * Project- and warehouse-scoped per security.md §23: every source table is
 * filtered by the same scope, so the KPIs, charts and monthly rows all agree.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('performance_dashboard')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$date_from    = $_GET['date_from'] ?? date('Y-01-01');
$date_to      = $_GET['date_to']   ?? date('Y-12-31');
$project_id   = (isset($_GET['project_id'])   && $_GET['project_id']   !== '') ? (int)$_GET['project_id']   : null;
$warehouse_id = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}
if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse is not in your assigned scope.']);
    exit;
}

/**
 * Build the project/warehouse-scope SQL fragment + extra params for a table
 * alias. $hasWarehouseCol must be false for tables with no warehouse_id
 * column (expenses is company-wide, not per-branch).
 */
function perf_scope(?int $project_id, ?int $warehouse_id, string $alias, array &$params, bool $hasWarehouseCol = true): string {
    $sql = '';
    if ($project_id !== null) { $params[] = $project_id; $sql .= " AND {$alias}.project_id = ?"; }
    else                      { $sql .= scopeFilterSqlNullable('project', $alias); }
    if ($hasWarehouseCol) {
        if ($warehouse_id !== null) { $params[] = $warehouse_id; $sql .= " AND {$alias}.warehouse_id = ?"; }
        else                        { $sql .= scopeFilterSqlNullable('warehouse', $alias); }
    }
    return $sql;
}

try {
    global $pdo;

    // ── Revenue: invoices + pos_sales (UNION ALL), same shape as get_sales_report.php ──
    $revenueTotal = function (string $dateFrom, string $dateTo) use ($pdo, $project_id, $warehouse_id): float {
        $inv_params = [$dateFrom, $dateTo];
        $inv_scope  = perf_scope($project_id, $warehouse_id, 'i', $inv_params);
        $pos_params = [$dateFrom, $dateTo];
        $pos_scope  = perf_scope($project_id, $warehouse_id, 'ps', $pos_params);

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total), 0) FROM (
                SELECT i.grand_total AS total
                  FROM invoices i
                 WHERE i.invoice_date BETWEEN ? AND ? AND i.status != 'cancelled' $inv_scope
                UNION ALL
                SELECT ps.grand_total AS total
                  FROM pos_sales ps
                 WHERE DATE(ps.sale_date) BETWEEN ? AND ? AND ps.sale_status = 'completed' $pos_scope
            ) AS combined
        ");
        $stmt->execute(array_merge($inv_params, $pos_params));
        return (float)$stmt->fetchColumn();
    };

    $revenueMonthly = function (string $dateFrom, string $dateTo) use ($pdo, $project_id, $warehouse_id): array {
        $inv_params = [$dateFrom, $dateTo];
        $inv_scope  = perf_scope($project_id, $warehouse_id, 'i', $inv_params);
        $pos_params = [$dateFrom, $dateTo];
        $pos_scope  = perf_scope($project_id, $warehouse_id, 'ps', $pos_params);

        $stmt = $pdo->prepare("
            SELECT label, SUM(value) AS value FROM (
                SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS label, i.grand_total AS value
                  FROM invoices i
                 WHERE i.invoice_date BETWEEN ? AND ? AND i.status != 'cancelled' $inv_scope
                UNION ALL
                SELECT DATE_FORMAT(ps.sale_date, '%Y-%m') AS label, ps.grand_total AS value
                  FROM pos_sales ps
                 WHERE DATE(ps.sale_date) BETWEEN ? AND ? AND ps.sale_status = 'completed' $pos_scope
            ) AS combined
          GROUP BY label
        ");
        $stmt->execute(array_merge($inv_params, $pos_params));
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    };

    // ── Totals per source ─────────────────────────────────────────────────
    $revenue = $revenueTotal($date_from, $date_to);

    $p = [$date_from, $date_to];
    $sc = perf_scope($project_id, $warehouse_id, 'po', $p);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM purchase_orders po
                            WHERE po.order_date BETWEEN ? AND ? AND po.status NOT IN ('rejected','cancelled') $sc");
    $stmt->execute($p);
    $direct_costs = (float)$stmt->fetchColumn();

    // Expenses are company-wide (no warehouse_id column) — project-scoped only.
    $p = [$date_from, $date_to];
    $sc = scopeFilterSqlNullable('project', 'e');
    if ($project_id !== null) { $p[] = $project_id; $sc = ' AND e.project_id = ?'; }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses e
                            WHERE e.expense_date BETWEEN ? AND ? AND e.status NOT IN ('rejected','cancelled') $sc");
    $stmt->execute($p);
    $expenses_total = (float)$stmt->fetchColumn();

    $gross_profit  = $revenue - $direct_costs;
    $net_profit    = $gross_profit - $expenses_total;
    $margin        = $revenue > 0 ? ($net_profit / $revenue) * 100 : 0;
    $expense_ratio = $revenue > 0 ? ($expenses_total / $revenue) * 100 : 0;

    // ── Monthly series per source ─────────────────────────────────────────
    $monthlyOf = function (string $table, string $alias, string $dateCol, string $amtExpr, string $statusClause, bool $hasWarehouseCol = true) use ($pdo, $date_from, $date_to, $project_id, $warehouse_id): array {
        $p  = [$date_from, $date_to];
        $sc = perf_scope($project_id, $warehouse_id, $alias, $p, $hasWarehouseCol);
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT($alias.$dateCol, '%Y-%m') AS k, COALESCE(SUM($amtExpr),0) AS v
              FROM $table $alias
             WHERE $alias.$dateCol BETWEEN ? AND ? $statusClause $sc
          GROUP BY k
        ");
        $stmt->execute($p);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    };

    $mRev  = $revenueMonthly($date_from, $date_to);
    $mCost = $monthlyOf('purchase_orders', 'po', 'order_date',   'grand_total', "AND po.status NOT IN ('rejected','cancelled')");
    $mExp  = $monthlyOf('expenses',        'e',  'expense_date', 'amount',      "AND e.status NOT IN ('rejected','cancelled')", false);

    $keys = array_unique(array_merge(array_keys($mRev), array_keys($mCost), array_keys($mExp)));
    sort($keys);

    $rows = [];
    foreach ($keys as $k) {
        if ($k === '' || $k === null) continue;
        $rev  = (float)($mRev[$k]  ?? 0);
        $cost = (float)($mCost[$k] ?? 0);
        $exp  = (float)($mExp[$k]  ?? 0);
        $net  = $rev - $cost - $exp;
        $rows[] = [
            'month'        => date('M Y', strtotime($k . '-01')),
            'revenue'      => $rev,
            'direct_costs' => $cost,
            'expenses'     => $exp,
            'net_profit'   => $net,
            'margin'       => $rev > 0 ? ($net / $rev) * 100 : 0,
        ];
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'revenue'       => $revenue,
            'direct_costs'  => $direct_costs,
            'expenses'      => $expenses_total,
            'gross_profit'  => $gross_profit,
            'net_profit'    => $net_profit,
            'margin'        => round($margin, 1),
            'expense_ratio' => round($expense_ratio, 1),
        ],
        'charts' => [
            'trend' => array_map(fn($r) => ['label' => $r['month'], 'revenue' => $r['revenue'], 'expenses' => $r['direct_costs'] + $r['expenses'], 'net' => $r['net_profit']], $rows),
            'breakdown' => [
                ['label' => 'Direct Costs',       'value' => round($direct_costs, 2)],
                ['label' => 'Operating Expenses', 'value' => round($expenses_total, 2)],
                ['label' => 'Net Profit',         'value' => round(max(0, $net_profit), 2)],
            ],
            'comparison' => [
                ['label' => 'Revenue',      'value' => round($revenue, 2)],
                ['label' => 'Direct Costs', 'value' => round($direct_costs, 2)],
                ['label' => 'Expenses',     'value' => round($expenses_total, 2)],
                ['label' => 'Net Profit',   'value' => round($net_profit, 2)],
            ],
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_performance_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
