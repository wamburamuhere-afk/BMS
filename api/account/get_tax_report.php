<?php
/**
 * api/account/get_tax_report.php
 *
 * AJAX data source for the Taxation & VAT report — output (sales) vs input
 * (purchase) tax, net payable, monthly reconciliation rows and chart data.
 *
 * Project-scoped per security.md §23 (invoices.project_id /
 * purchase_orders.project_id).
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) { header('Content-Type: application/json'); }

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('tax_report')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

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

    // Output tax (sales/invoices)
    $po = [$date_from, $date_to];
    $invScope = '';
    if ($project_id !== null) { $invScope = " AND i.project_id = ?"; $po[] = $project_id; }
    else                      { $invScope = scopeFilterSqlNullable('project', 'i'); }
    $stmt = $pdo->prepare("
        SELECT COUNT(i.invoice_id)              AS cnt,
               COALESCE(SUM(i.subtotal), 0)     AS taxable,
               COALESCE(SUM(i.tax_amount), 0)   AS tax
          FROM invoices i
         WHERE i.invoice_date BETWEEN ? AND ? AND i.status NOT IN ('cancelled','draft') $invScope
    ");
    $stmt->execute($po);
    $out = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Input tax (purchases)
    $pp = [$date_from, $date_to];
    $poScope = '';
    if ($project_id !== null) { $poScope = " AND po.project_id = ?"; $pp[] = $project_id; }
    else                      { $poScope = scopeFilterSqlNullable('project', 'po'); }
    $stmt = $pdo->prepare("
        SELECT COUNT(po.purchase_order_id)       AS cnt,
               COALESCE(SUM(po.total_amount), 0) AS taxable,
               COALESCE(SUM(po.tax_amount), 0)   AS tax
          FROM purchase_orders po
         WHERE po.order_date BETWEEN ? AND ? AND po.status NOT IN ('cancelled','draft','rejected') $poScope
    ");
    $stmt->execute($pp);
    $in = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total_output = (float)($out['tax'] ?? 0);
    $total_input  = (float)($in['tax'] ?? 0);
    $net_payable  = $total_output - $total_input;

    // Monthly output map
    $po2 = [$date_from, $date_to];
    $invScope2 = ($project_id !== null) ? (function() use (&$po2,$project_id){ $po2[]=$project_id; return " AND i.project_id = ?"; })() : scopeFilterSqlNullable('project', 'i');
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(i.invoice_date,'%Y-%m') AS m, COALESCE(SUM(i.tax_amount),0) AS tax
          FROM invoices i
         WHERE i.invoice_date BETWEEN ? AND ? AND i.status NOT IN ('cancelled','draft') $invScope2
      GROUP BY m
    ");
    $stmt->execute($po2);
    $mOut = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Monthly input map
    $pp2 = [$date_from, $date_to];
    $poScope2 = ($project_id !== null) ? (function() use (&$pp2,$project_id){ $pp2[]=$project_id; return " AND po.project_id = ?"; })() : scopeFilterSqlNullable('project', 'po');
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(po.order_date,'%Y-%m') AS m, COALESCE(SUM(po.tax_amount),0) AS tax
          FROM purchase_orders po
         WHERE po.order_date BETWEEN ? AND ? AND po.status NOT IN ('cancelled','draft','rejected') $poScope2
      GROUP BY m
    ");
    $stmt->execute($pp2);
    $mIn = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $keys = array_unique(array_merge(array_keys($mOut), array_keys($mIn)));
    sort($keys);
    $rows = [];
    foreach ($keys as $k) {
        if ($k === '' || $k === null) continue;
        $o = (float)($mOut[$k] ?? 0);
        $ii = (float)($mIn[$k] ?? 0);
        $rows[] = ['month'=>date('M Y', strtotime($k.'-01')), 'output'=>$o, 'input'=>$ii, 'net'=>$o - $ii];
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'output_tax'   => $total_output,
            'input_tax'    => $total_input,
            'net_payable'  => $net_payable,
            'sales_count'  => (int)($out['cnt'] ?? 0),
            'purchase_count' => (int)($in['cnt'] ?? 0),
        ],
        'charts' => [
            'monthly'  => array_map(fn($r) => ['label'=>$r['month'],'output'=>$r['output'],'input'=>$r['input']], $rows),
            'split'    => [
                ['label'=>'Output Tax (Collected)', 'value'=>round($total_output,2)],
                ['label'=>'Input Tax (Paid)',       'value'=>round($total_input,2)],
            ],
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_tax_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
