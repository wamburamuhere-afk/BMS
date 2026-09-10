<?php
/**
 * api/account/get_customer_analysis_report.php
 *
 * AJAX data source for the Customer Analysis report — summary, three chart
 * datasets, and per-customer rows as JSON.
 *
 * Revenue = invoices.grand_total + pos_sales.grand_total (actual realised
 * sales — same definition as get_sales_report.php, the reference
 * implementation). 2026-09-10: used to read sales_orders.grand_total only,
 * so a POS-only tenant (Sales module off) always showed zero customer
 * activity here despite real POS sales. sales_orders is deliberately NOT
 * summed alongside invoices, since an order that gets invoiced would
 * otherwise be counted twice.
 *
 * Project- and warehouse-scoped per security.md §23: one pair of scope
 * clauses (invoices + pos_sales) feeds the summary, every chart, and the rows.
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
if (!canView('customer_analysis')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$date_from    = $_GET['date_from']    ?? date('Y-01-01');
$date_to      = $_GET['date_to']      ?? date('Y-12-31');
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

try {
    global $pdo;

    // ── Invoice WHERE ─────────────────────────────────────────────────────
    $inv_params = [$date_from, $date_to];
    $inv_where  = ["i.invoice_date BETWEEN ? AND ?", "i.status != 'cancelled'"];
    $inv_scope  = '';
    if ($project_id !== null) { $inv_where[] = "i.project_id = ?"; $inv_params[] = $project_id; }
    else                      { $inv_scope  .= scopeFilterSqlNullable('project', 'i'); }
    if ($warehouse_id !== null) { $inv_where[] = "i.warehouse_id = ?"; $inv_params[] = $warehouse_id; }
    else                        { $inv_scope  .= scopeFilterSqlNullable('warehouse', 'i'); }
    $inv_where_sql = implode(' AND ', $inv_where) . $inv_scope;

    // ── POS WHERE ─────────────────────────────────────────────────────────
    $pos_params = [$date_from, $date_to];
    $pos_where  = ["DATE(ps.sale_date) BETWEEN ? AND ?", "ps.sale_status = 'completed'"];
    $pos_scope  = '';
    if ($project_id !== null) { $pos_where[] = "ps.project_id = ?"; $pos_params[] = $project_id; }
    else                      { $pos_scope  .= scopeFilterSqlNullable('project', 'ps'); }
    if ($warehouse_id !== null) { $pos_where[] = "ps.warehouse_id = ?"; $pos_params[] = $warehouse_id; }
    else                        { $pos_scope  .= scopeFilterSqlNullable('warehouse', 'ps'); }
    $pos_where_sql = implode(' AND ', $pos_where) . $pos_scope;

    $merged = array_merge($inv_params, $pos_params);

    $combinedSql = "
        SELECT i.customer_id AS cust_id, i.grand_total AS total, i.invoice_date AS order_date
          FROM invoices i
         WHERE $inv_where_sql
        UNION ALL
        SELECT ps.customer_id AS cust_id, ps.grand_total AS total, ps.sale_date AS order_date
          FROM pos_sales ps
         WHERE $pos_where_sql
    ";

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT cust_id) AS active_customers,
               COUNT(*)                AS total_orders,
               COALESCE(SUM(total), 0) AS total_revenue
          FROM ($combinedSql) combined
    ");
    $stmt->execute($merged);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $active = (int)($summary['active_customers'] ?? 0);
    $totrev = (float)($summary['total_revenue'] ?? 0);

    // ── Chart 1 + rows: per customer ──────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.customer_name, 'Walk-in') AS customer_name,
               COUNT(*)                  AS total_orders,
               COALESCE(SUM(total), 0)  AS total_spent,
               COALESCE(AVG(total), 0)  AS avg_order,
               MAX(order_date)           AS last_order
          FROM ($combinedSql) combined
          LEFT JOIN customers c ON combined.cust_id = c.customer_id
      GROUP BY combined.cust_id, c.customer_name
      ORDER BY total_spent DESC
    ");
    $stmt->execute($merged);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $top_customers = array_slice(array_map(fn($r) => ['name' => $r['customer_name'], 'total' => (float)$r['total_spent']], $rows), 0, 8);

    // Revenue concentration: top 5 + Others
    $top5 = array_slice($rows, 0, 5);
    $top5_sum = array_sum(array_map(fn($r) => (float)$r['total_spent'], $top5));
    $concentration = array_map(fn($r) => ['label' => $r['customer_name'], 'value' => (float)$r['total_spent']], $top5);
    if ($totrev - $top5_sum > 0.01) {
        $concentration[] = ['label' => 'Others', 'value' => round($totrev - $top5_sum, 2)];
    }

    // ── Chart 3: monthly revenue trend ────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(order_date, '%Y-%m') AS label,
               COALESCE(SUM(total), 0)          AS value
          FROM ($combinedSql) combined
      GROUP BY label ORDER BY label ASC LIMIT 24
    ");
    $stmt->execute($merged);
    $monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'summary' => [
            'active_customers' => $active,
            'total_orders'     => (int)($summary['total_orders'] ?? 0),
            'total_revenue'    => $totrev,
            'avg_per_customer' => $active > 0 ? round($totrev / $active, 2) : 0,
        ],
        'charts' => [
            'top_customers' => $top_customers,
            'concentration' => $concentration,
            'monthly'       => $monthly,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_customer_analysis_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
