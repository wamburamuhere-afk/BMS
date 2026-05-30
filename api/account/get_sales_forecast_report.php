<?php
/**
 * api/account/get_sales_forecast_report.php
 *
 * AJAX data source for the Sales Forecast report. Builds a baseline
 * moving-average projection for the next N months from the trailing 12 months
 * of sales, with conservative (-15%) and optimistic (+15%) bands.
 *
 * Project-scoped per security.md §23 (sales_orders.project_id).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('sales_forecast')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$horizon    = isset($_GET['horizon']) ? max(3, min(12, (int)$_GET['horizon'])) : 6;
$project_id = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    $p = [];
    $sc = '';
    if ($project_id !== null) { $sc = " AND so.project_id = ?"; $p[] = $project_id; }
    else                      { $sc = scopeFilterSqlNullable('project', 'so'); }

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(so.order_date, '%Y-%m') AS m, COALESCE(SUM(so.grand_total),0) AS total
          FROM sales_orders so
         WHERE so.order_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
           AND so.status != 'cancelled' $sc
      GROUP BY m ORDER BY m ASC
    ");
    $stmt->execute($p);
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
