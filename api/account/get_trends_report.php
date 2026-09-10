<?php
/**
 * api/account/get_trends_report.php
 *
 * AJAX data source for the Historical Trends report — monthly Sales vs Expenses
 * vs Profit over a window.
 *
 * Sales per month = invoices.grand_total + pos_sales.grand_total (actual
 * realised sales — same definition as get_sales_report.php, the reference
 * implementation). 2026-09-10: used to read sales_orders.grand_total only, so
 * a POS-only tenant (Sales module off) always showed a flat zero sales trend
 * despite real POS activity. sales_orders is deliberately NOT unioned in
 * alongside them, since an order that gets invoiced would otherwise be
 * counted twice.
 *
 * Project-scoped per security.md §23 (invoices/pos_sales/expenses.project_id);
 * warehouse-scoped on the sales side too (expenses has no warehouse_id — it's
 * company-wide, not per-branch).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('trends_analysis')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$months_back  = isset($_GET['months']) ? max(3, min(36, (int)$_GET['months'])) : 12;
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

    $months = [];
    for ($i = $months_back - 1; $i >= 0; $i--) { $months[] = date('Y-m', strtotime("-$i months")); }
    $ph = implode(',', array_fill(0, count($months), '?'));

    // Sales per month: invoices + pos_sales (UNION ALL), each own scope block.
    $inv_params = $months;
    $inv_scope  = '';
    if ($project_id !== null)   { $inv_scope .= " AND i.project_id = ?";   $inv_params[] = $project_id; }
    else                        { $inv_scope .= scopeFilterSqlNullable('project', 'i'); }
    if ($warehouse_id !== null) { $inv_scope .= " AND i.warehouse_id = ?"; $inv_params[] = $warehouse_id; }
    else                        { $inv_scope .= scopeFilterSqlNullable('warehouse', 'i'); }

    $pos_params = $months;
    $pos_scope  = '';
    if ($project_id !== null)   { $pos_scope .= " AND ps.project_id = ?";   $pos_params[] = $project_id; }
    else                        { $pos_scope .= scopeFilterSqlNullable('project', 'ps'); }
    if ($warehouse_id !== null) { $pos_scope .= " AND ps.warehouse_id = ?"; $pos_params[] = $warehouse_id; }
    else                        { $pos_scope .= scopeFilterSqlNullable('warehouse', 'ps'); }

    $stmt = $pdo->prepare("
        SELECT m, SUM(total) AS total FROM (
            SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS m, i.grand_total AS total
              FROM invoices i
             WHERE DATE_FORMAT(i.invoice_date,'%Y-%m') IN ($ph) AND i.status != 'cancelled' $inv_scope
            UNION ALL
            SELECT DATE_FORMAT(ps.sale_date, '%Y-%m') AS m, ps.grand_total AS total
              FROM pos_sales ps
             WHERE DATE_FORMAT(ps.sale_date,'%Y-%m') IN ($ph) AND ps.sale_status = 'completed' $pos_scope
        ) AS combined
      GROUP BY m
    ");
    $stmt->execute(array_merge($inv_params, $pos_params));
    $sales = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Expenses per month (company-wide — no warehouse dimension).
    $p = $months;
    $sc = '';
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
