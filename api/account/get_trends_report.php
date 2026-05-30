<?php
/**
 * api/account/get_trends_report.php
 *
 * AJAX data source for the Historical Trends report — monthly Sales vs Expenses
 * vs Profit over a window. Project-scoped per security.md §23
 * (sales_orders.project_id, expenses.project_id).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('trends_analysis')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$months_back = isset($_GET['months']) ? max(3, min(36, (int)$_GET['months'])) : 12;
$project_id  = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: this project is not in your assigned scope.']); exit;
}

try {
    global $pdo;

    $months = [];
    for ($i = $months_back - 1; $i >= 0; $i--) { $months[] = date('Y-m', strtotime("-$i months")); }
    $ph = implode(',', array_fill(0, count($months), '?'));

    // Sales per month
    $p = $months;
    $sc = '';
    if ($project_id !== null) { $sc = " AND so.project_id = ?"; $p[] = $project_id; }
    else                      { $sc = scopeFilterSqlNullable('project', 'so'); }
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(so.order_date, '%Y-%m') AS m, COALESCE(SUM(so.grand_total),0) AS total
          FROM sales_orders so
         WHERE DATE_FORMAT(so.order_date,'%Y-%m') IN ($ph) AND so.status != 'cancelled' $sc
      GROUP BY m
    ");
    $stmt->execute($p);
    $sales = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Expenses per month
    $p = $months;
    if ($project_id !== null) { $sc = " AND e.project_id = ?"; $p[] = $project_id; }
    else                      { $sc = scopeFilterSqlNullable('project', 'e'); }
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(e.expense_date, '%Y-%m') AS m, COALESCE(SUM(e.amount),0) AS total
          FROM expenses e
         WHERE DATE_FORMAT(e.expense_date,'%Y-%m') IN ($ph) AND e.status != 'rejected' $sc
      GROUP BY m
    ");
    $stmt->execute($p);
    $exp = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $rows = []; $tot_s = 0; $tot_e = 0;
    foreach ($months as $m) {
        $s = (float)($sales[$m] ?? 0);
        $e = (float)($exp[$m]   ?? 0);
        $tot_s += $s; $tot_e += $e;
        $rows[] = ['month'=>date('M Y', strtotime($m.'-01')), 'sales'=>$s, 'expenses'=>$e, 'profit'=>$s - $e];
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_sales'    => round($tot_s, 2),
            'total_expenses' => round($tot_e, 2),
            'total_profit'   => round($tot_s - $tot_e, 2),
            'avg_monthly'    => count($rows) > 0 ? round($tot_s / count($rows), 2) : 0,
        ],
        'charts' => [
            'trend' => array_map(fn($r) => ['label'=>$r['month'],'sales'=>$r['sales'],'expenses'=>$r['expenses'],'profit'=>$r['profit']], $rows),
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_trends_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
