<?php
/**
 * api/account/get_performance_report.php
 *
 * AJAX data source for the Business Performance dashboard — summary KPIs,
 * three chart datasets, and a monthly breakdown table as JSON.
 *
 *   Revenue       = sales_orders.grand_total
 *   Direct Costs  = purchase_orders.grand_total (COGS proxy)
 *   Expenses      = expenses.amount
 *   Gross Profit  = Revenue - Direct Costs
 *   Net Profit    = Gross Profit - Expenses
 *
 * Project-scoped per security.md §23: every source table (sales_orders,
 * purchase_orders, expenses) is filtered by the same project scope, so the
 * KPIs, charts and monthly rows all reflect the user's projects together.
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

$date_from  = $_GET['date_from'] ?? date('Y-01-01');
$date_to    = $_GET['date_to']   ?? date('Y-12-31');
$project_id = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}

/** Build the project-scope SQL fragment + extra param for a table alias. */
function perf_scope(?int $project_id, string $alias, array &$params): string {
    if ($project_id !== null) {
        $params[] = $project_id;
        return " AND {$alias}.project_id = ?";
    }
    return scopeFilterSqlNullable('project', $alias);
}

try {
    global $pdo;

    // ── Totals per source ─────────────────────────────────────────────────
    $p = [$date_from, $date_to];
    $sc = perf_scope($project_id, 'so', $p);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM sales_orders so
                            WHERE so.order_date BETWEEN ? AND ? AND so.status != 'cancelled' $sc");
    $stmt->execute($p);
    $revenue = (float)$stmt->fetchColumn();

    $p = [$date_from, $date_to];
    $sc = perf_scope($project_id, 'po', $p);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM purchase_orders po
                            WHERE po.order_date BETWEEN ? AND ? AND po.status NOT IN ('rejected','cancelled') $sc");
    $stmt->execute($p);
    $direct_costs = (float)$stmt->fetchColumn();

    $p = [$date_from, $date_to];
    $sc = perf_scope($project_id, 'e', $p);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses e
                            WHERE e.expense_date BETWEEN ? AND ? AND e.status NOT IN ('rejected','cancelled') $sc");
    $stmt->execute($p);
    $expenses_total = (float)$stmt->fetchColumn();

    $gross_profit  = $revenue - $direct_costs;
    $net_profit    = $gross_profit - $expenses_total;
    $margin        = $revenue > 0 ? ($net_profit / $revenue) * 100 : 0;
    $expense_ratio = $revenue > 0 ? ($expenses_total / $revenue) * 100 : 0;

    // ── Monthly series per source ─────────────────────────────────────────
    $monthlyOf = function (string $table, string $alias, string $dateCol, string $amtExpr, string $statusClause) use ($pdo, $date_from, $date_to, $project_id): array {
        $p  = [$date_from, $date_to];
        $sc = perf_scope($project_id, $alias, $p);
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT($alias.$dateCol, '%Y-%m') AS k, COALESCE(SUM($amtExpr),0) AS v
              FROM $table $alias
             WHERE $alias.$dateCol BETWEEN ? AND ? $statusClause $sc
          GROUP BY k
        ");
        $stmt->execute($p);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    };

    $mRev  = $monthlyOf('sales_orders',    'so', 'order_date',   'grand_total', "AND so.status != 'cancelled'");
    $mCost = $monthlyOf('purchase_orders', 'po', 'order_date',   'grand_total', "AND po.status NOT IN ('rejected','cancelled')");
    $mExp  = $monthlyOf('expenses',        'e',  'expense_date', 'amount',      "AND e.status NOT IN ('rejected','cancelled')");

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
