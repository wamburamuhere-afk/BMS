<?php
/**
 * api/account/get_product_analysis_report.php
 *
 * AJAX data source for the Product Performance report — summary, three chart
 * datasets, and per-product rows. Revenue = SUM(quantity * unit_price) on
 * sales_order_items, scoped to the orders the user may see.
 *
 * Project-scoped per security.md §23 via sales_orders.project_id.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('product_analysis')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$date_from  = $_GET['date_from'] ?? date('Y-01-01');
$date_to    = $_GET['date_to']   ?? date('Y-12-31');
$project_id = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success'=>false,'message'=>'Invalid date range']); exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    // Order-side filter (date + status + project scope) lives in the JOIN so a
    // product with no in-scope orders aggregates to 0 and drops out via HAVING.
    $params = [$date_from, $date_to];
    $orderCond = "so.order_date BETWEEN ? AND ? AND so.status != 'cancelled'";
    if ($project_id !== null) { $orderCond .= " AND so.project_id = ?"; $params[] = $project_id; }
    else                      { $orderCond .= scopeFilterSqlNullable('project', 'so'); }

    $stmt = $pdo->prepare("
        SELECT p.product_code, p.product_name,
               COALESCE(c.category_name, 'Uncategorised') AS category,
               COUNT(soi.order_item_id)                   AS times_sold,
               COALESCE(SUM(soi.quantity), 0)             AS qty_sold,
               COALESCE(SUM(soi.quantity * soi.unit_price), 0) AS revenue,
               COALESCE(AVG(soi.unit_price), 0)           AS avg_price
          FROM products p
          LEFT JOIN categories c ON p.category_id = c.category_id
          LEFT JOIN sales_order_items soi ON p.product_id = soi.product_id
          LEFT JOIN sales_orders so ON soi.order_id = so.sales_order_id AND $orderCond
      GROUP BY p.product_id, p.product_code, p.product_name, c.category_name
        HAVING qty_sold > 0
      ORDER BY revenue DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_revenue = array_sum(array_map(fn($r) => (float)$r['revenue'], $rows));
    $total_units   = array_sum(array_map(fn($r) => (float)$r['qty_sold'], $rows));

    $top_products = array_slice(array_map(fn($r) => ['name'=>$r['product_name'],'total'=>(float)$r['revenue']], $rows), 0, 8);
    $top_units    = array_slice(array_map(fn($r) => ['name'=>$r['product_name'],'value'=>(float)$r['qty_sold']],
                        (function($rows){ usort($rows, fn($a,$b)=>(float)$b['qty_sold']<=>(float)$a['qty_sold']); return $rows; })($rows)), 0, 8);

    $byCat = [];
    foreach ($rows as $r) { $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float)$r['revenue']; }
    arsort($byCat);
    $by_category = array_map(fn($k,$v) => ['label'=>$k,'value'=>round($v,2)], array_keys($byCat), array_values($byCat));

    echo json_encode([
        'success' => true,
        'summary' => [
            'products_sold' => count($rows),
            'total_revenue' => round($total_revenue, 2),
            'total_units'   => $total_units,
            'avg_price'     => count($rows) > 0 ? round(array_sum(array_map(fn($r)=>(float)$r['avg_price'],$rows))/count($rows),2) : 0,
        ],
        'charts' => [
            'top_products' => $top_products,
            'by_category'  => $by_category,
            'top_units'    => $top_units,
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_product_analysis_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
