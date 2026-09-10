<?php
/**
 * api/account/get_product_analysis_report.php
 *
 * AJAX data source for the Product Performance report — summary, three chart
 * datasets, and per-product rows.
 *
 * Revenue/quantity sold = SUM(quantity * unit_price) / SUM(quantity) across
 * invoice_items + pos_sale_items (actual realised sale lines — same
 * definition as get_sales_report.php, the reference implementation).
 * 2026-09-10: used to read sales_order_items only, so a POS-only tenant
 * (Sales module off) always showed every product as unsold despite real POS
 * activity. sales_order_items is deliberately NOT unioned in alongside them,
 * since a sales order that gets invoiced would otherwise be counted twice.
 *
 * Project- and warehouse-scoped per security.md §23 via the parent
 * invoices/pos_sales row (invoice_items/pos_sale_items carry no scope
 * columns of their own).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('product_analysis')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$date_from    = $_GET['date_from']    ?? date('Y-01-01');
$date_to      = $_GET['date_to']      ?? date('Y-12-31');
$project_id   = (isset($_GET['project_id'])   && $_GET['project_id']   !== '') ? (int)$_GET['project_id']   : null;
$warehouse_id = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    echo json_encode(['success'=>false,'message'=>'Invalid date range']); exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your assigned scope.']); exit;
}
if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this warehouse is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    // Invoice-side condition (lives in the JOIN, same shape as before). Each
    // side keeps its OWN params array so the merged order exactly matches
    // the placeholder order in the final SQL text (inv's, then pos's).
    $inv_params = [$date_from, $date_to];
    $invCond = "i.invoice_date BETWEEN ? AND ? AND i.status != 'cancelled'";
    if ($project_id !== null)   { $invCond .= " AND i.project_id = ?";   $inv_params[] = $project_id; }
    else                        { $invCond .= scopeFilterSqlNullable('project', 'i'); }
    if ($warehouse_id !== null) { $invCond .= " AND i.warehouse_id = ?"; $inv_params[] = $warehouse_id; }
    else                        { $invCond .= scopeFilterSqlNullable('warehouse', 'i'); }

    // POS-side condition.
    $pos_params = [$date_from, $date_to];
    $posCond = "DATE(ps.sale_date) BETWEEN ? AND ? AND ps.sale_status = 'completed'";
    if ($project_id !== null)   { $posCond .= " AND ps.project_id = ?";   $pos_params[] = $project_id; }
    else                        { $posCond .= scopeFilterSqlNullable('project', 'ps'); }
    if ($warehouse_id !== null) { $posCond .= " AND ps.warehouse_id = ?"; $pos_params[] = $warehouse_id; }
    else                        { $posCond .= scopeFilterSqlNullable('warehouse', 'ps'); }
    $params = array_merge($inv_params, $pos_params);

    // Aggregated PER-PRODUCT first (each source individually), so joining
    // two one-to-many item tables to `products` at once can't fan out and
    // inflate SUMs — a classic multi-join bug avoided here on purpose.
    $combined = "
        SELECT ii.product_id, ii.quantity AS qty, ii.unit_price AS unit_price, (ii.quantity * ii.unit_price) AS revenue
          FROM invoice_items ii
          JOIN invoices i ON ii.invoice_id = i.invoice_id AND $invCond
        UNION ALL
        SELECT psi.product_id, psi.quantity, psi.unit_price, (psi.quantity * psi.unit_price)
          FROM pos_sale_items psi
          JOIN pos_sales ps ON psi.sale_id = ps.sale_id AND $posCond
    ";

    $stmt = $pdo->prepare("
        SELECT p.product_code, p.product_name,
               COALESCE(c.category_name, 'Uncategorised') AS category,
               COUNT(ci.product_id)                       AS times_sold,
               COALESCE(SUM(ci.qty), 0)                   AS qty_sold,
               COALESCE(SUM(ci.revenue), 0)                AS revenue,
               COALESCE(AVG(ci.unit_price), 0)             AS avg_price
          FROM products p
          LEFT JOIN categories c ON p.category_id = c.category_id
          LEFT JOIN ($combined) ci ON ci.product_id = p.product_id
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
