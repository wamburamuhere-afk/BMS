<?php
/**
 * api/account/get_sales_forecast_report.php
 *
 * AJAX data source for the Sales Forecast report. Builds a baseline
 * moving-average projection for the next N months from the trailing 12 months
 * of sales, with conservative (-15%) and optimistic (+15%) bands.
 *
 * Trailing history = invoices.grand_total + pos_sales.grand_total (actual
 * realised sales — same definition as get_sales_report.php, the reference
 * implementation). 2026-09-10: used to read sales_orders.grand_total only,
 * so a POS-only tenant (Sales module off) always forecast zero despite real
 * POS activity. sales_orders is deliberately NOT unioned in alongside them,
 * since an order that gets invoiced would otherwise be counted twice.
 *
 * Project- and warehouse-scoped per security.md §23.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('sales_forecast')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$horizon      = isset($_GET['horizon']) ? max(3, min(12, (int)$_GET['horizon'])) : 6;
$project_id   = (isset($_GET['project_id'])   && $_GET['project_id']   !== '') ? (int)$_GET['project_id']   : null;
$warehouse_id = (isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '') ? (int)$_GET['warehouse_id'] : null;

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your assigned scope.']); exit;
}
if ($warehouse_id !== null && !userCan('warehouse', $warehouse_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this warehouse is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    $inv_scope = '';
    $inv_params = [];
    if ($project_id !== null)   { $inv_scope .= " AND i.project_id = ?";   $inv_params[] = $project_id; }
    else                        { $inv_scope .= scopeFilterSqlNullable('project', 'i'); }
    if ($warehouse_id !== null) { $inv_scope .= " AND i.warehouse_id = ?"; $inv_params[] = $warehouse_id; }
    else                        { $inv_scope .= scopeFilterSqlNullable('warehouse', 'i'); }

    $pos_scope = '';
    $pos_params = [];
    if ($project_id !== null)   { $pos_scope .= " AND ps.project_id = ?";   $pos_params[] = $project_id; }
    else                        { $pos_scope .= scopeFilterSqlNullable('project', 'ps'); }
    if ($warehouse_id !== null) { $pos_scope .= " AND ps.warehouse_id = ?"; $pos_params[] = $warehouse_id; }
    else                        { $pos_scope .= scopeFilterSqlNullable('warehouse', 'ps'); }

    $stmt = $pdo->prepare("
        SELECT m, SUM(total) AS total FROM (
            SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS m, i.grand_total AS total
              FROM invoices i
             WHERE i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
               AND i.status != 'cancelled' $inv_scope
            UNION ALL
            SELECT DATE_FORMAT(ps.sale_date, '%Y-%m') AS m, ps.grand_total AS total
              FROM pos_sales ps
             WHERE ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
               AND ps.sale_status = 'completed' $pos_scope
        ) AS combined
      GROUP BY m ORDER BY m ASC
    ");
    $stmt->execute(array_merge($inv_params, $pos_params));
    $hist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = array_sum(array_map(fn($r) => (float)$r['total'], $hist));
    $avg   = count($hist) > 0 ? $total / count($hist) : 0;

    $historical = array_map(fn($r) => ['label'=>date('M Y', strtotime($r['m'].'-01')), 'value'=>(float)$r['total']], $hist);

    $forecast = [];
    $last = count($hist) > 0 ? $hist[count($hist)-1]['m'] : date('Y-m');
    for ($i = 1; $i <= $horizon; $i++) {
        $ts = strtotime($last.'-01 +'.$i.' month');
        $forecast[] = [
            'month'        => date('M Y', $ts),
            'conservative' => round($avg * 0.85, 2),
            'projection'   => round($avg, 2),
            'optimistic'   => round($avg * 1.15, 2),
        ];
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'avg_monthly'      => round($avg, 2),
            'trailing_total'   => round($total, 2),
            'horizon'          => $horizon,
            'projected_total'  => round($avg * $horizon, 2),
        ],
        'charts' => [
            'historical' => $historical,
            'forecast'   => $forecast,
        ],
        'rows' => $forecast,
    ]);

} catch (Throwable $e) {
    error_log('get_sales_forecast_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
