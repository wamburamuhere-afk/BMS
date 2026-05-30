<?php
/**
 * api/account/get_customer_analysis_report.php
 *
 * AJAX data source for the Customer Analysis report — summary, three chart
 * datasets, and per-customer rows as JSON. Revenue = sales_orders.total_amount.
 *
 * Project-scoped per security.md §23 (sales_orders.project_id): one scope
 * clause feeds the summary, every chart, and the rows.
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

try {
    global $pdo;

    $params = [$date_from, $date_to];
    $where  = ["so.order_date BETWEEN ? AND ?", "so.status != 'cancelled'"];
    $scope  = '';
    if ($project_id !== null) {
        $where[]  = "so.project_id = ?";
        $params[] = $project_id;
    } else {
        $scope = scopeFilterSqlNullable('project', 'so');
    }
    $where_sql = implode(' AND ', $where) . $scope;

    // ── Summary ───────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT so.customer_id)        AS active_customers,
               COUNT(so.sales_order_id)              AS total_orders,
               COALESCE(SUM(so.grand_total), 0)     AS total_revenue
          FROM sales_orders so
         WHERE $where_sql
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $active = (int)($summary['active_customers'] ?? 0);
    $totrev = (float)($summary['total_revenue'] ?? 0);

    // ── Chart 1 + rows: per customer ──────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COALESCE(c.customer_name, 'Walk-in') AS customer_name,
               COUNT(so.sales_order_id)             AS total_orders,
               COALESCE(SUM(so.grand_total), 0)    AS total_spent,
               COALESCE(AVG(so.grand_total), 0)    AS avg_order,
               MAX(so.order_date)                   AS last_order
          FROM sales_orders so
          LEFT JOIN customers c ON so.customer_id = c.customer_id
         WHERE $where_sql
      GROUP BY so.customer_id, c.customer_name
      ORDER BY total_spent DESC
    ");
    $stmt->execute($params);
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
        SELECT DATE_FORMAT(so.order_date, '%Y-%m') AS label,
               COALESCE(SUM(so.grand_total), 0)   AS value
          FROM sales_orders so
         WHERE $where_sql
      GROUP BY label ORDER BY label ASC LIMIT 24
    ");
    $stmt->execute($params);
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
