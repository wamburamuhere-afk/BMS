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
require_once __DIR__ . '/../../core/vat.php';

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

    // VAT OUT — output tax on SALES invoices that have passed approval. Same
    // documents & criteria that post to the Output VAT Payable control account,
    // so this report reconciles with the Balance Sheet's VAT line.
    $po = [$date_from, $date_to];
    $invScope = '';
    if ($project_id !== null) { $invScope = " AND i.project_id = ?"; $po[] = $project_id; }
    else                      { $invScope = scopeFilterSqlNullable('project', 'i'); }
    $stmt = $pdo->prepare("
        SELECT COUNT(i.invoice_id)              AS cnt,
               COALESCE(SUM(i.subtotal), 0)     AS taxable,
               COALESCE(SUM(i.tax_amount), 0)   AS tax
          FROM invoices i
         WHERE i.invoice_date BETWEEN ? AND ? AND i.status IN ('approved','paid','partial') $invScope
    ");
    $stmt->execute($po);
    $out = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // VAT IN — input tax on RECEIVED invoices (supplier_invoices), the real bill,
    // that have passed approval. Matches the Input VAT Recoverable control
    // account (was previously read from purchase_orders — a different source).
    $pp = [$date_from, $date_to];
    $siScope = '';
    if ($project_id !== null) { $siScope = " AND si.project_id = ?"; $pp[] = $project_id; }
    else                      { $siScope = scopeFilterSqlNullable('project', 'si'); }
    $stmt = $pdo->prepare("
        SELECT COUNT(si.id)                      AS cnt,
               COALESCE(SUM(si.subtotal), 0)     AS taxable,
               COALESCE(SUM(si.tax_amount), 0)   AS tax
          FROM supplier_invoices si
         WHERE si.date_raised BETWEEN ? AND ? AND si.status IN ('approved','paid') $siScope
    ");
    $stmt->execute($pp);
    $in = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total_output = (float)($out['tax'] ?? 0);
    $total_input  = (float)($in['tax'] ?? 0);
    $net_payable  = $total_output - $total_input;

    // Monthly VAT OUT map (same source/criteria as the summary above)
    $po2 = [$date_from, $date_to];
    $invScope2 = ($project_id !== null) ? (function() use (&$po2,$project_id){ $po2[]=$project_id; return " AND i.project_id = ?"; })() : scopeFilterSqlNullable('project', 'i');
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(i.invoice_date,'%Y-%m') AS m, COALESCE(SUM(i.tax_amount),0) AS tax
          FROM invoices i
         WHERE i.invoice_date BETWEEN ? AND ? AND i.status IN ('approved','paid','partial') $invScope2
      GROUP BY m
    ");
    $stmt->execute($po2);
    $mOut = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Monthly VAT IN map (received invoices, same source/criteria as above)
    $pp2 = [$date_from, $date_to];
    $siScope2 = ($project_id !== null) ? (function() use (&$pp2,$project_id){ $pp2[]=$project_id; return " AND si.project_id = ?"; })() : scopeFilterSqlNullable('project', 'si');
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(si.date_raised,'%Y-%m') AS m, COALESCE(SUM(si.tax_amount),0) AS tax
          FROM supplier_invoices si
         WHERE si.date_raised BETWEEN ? AND ? AND si.status IN ('approved','paid') $siScope2
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
        $rows[] = ['month'=>date('M Y', strtotime($k.'-01')), 'tax_type'=>'VAT (18%)', 'output'=>$o, 'input'=>$ii, 'net'=>$o - $ii];
    }

    // Ledger position — Output VAT (liability) and Input VAT (asset) summed
    // directly from live documents (what the Balance Sheet shows). When the
    // report's date range covers everything, output_tax/input_tax should equal
    // ledger.output/ledger.input. A mismatch is an immediate red flag (unposted
    // VAT, e.g. an invoice approved before the feature) — easy to spot.
    $ledger = (function_exists('vatNetPosition') && $project_id === null)
        ? vatNetPosition($pdo)
        : ['output' => null, 'input' => null, 'net' => null, 'label' => null];

    echo json_encode([
        'success' => true,
        'summary' => [
            'output_tax'   => $total_output,   // VAT OUT
            'input_tax'    => $total_input,    // VAT IN
            'net_payable'  => $net_payable,
            'sales_count'  => (int)($out['cnt'] ?? 0),
            'purchase_count' => (int)($in['cnt'] ?? 0),
        ],
        'ledger' => $ledger,   // control-account balances for reconciliation
        'charts' => [
            'monthly'  => array_map(fn($r) => ['label'=>$r['month'],'output'=>$r['output'],'input'=>$r['input']], $rows),
            'split'    => [
                ['label'=>'Tax Out',  'value'=>round($total_output,2)],
                ['label'=>'Tax In',   'value'=>round($total_input,2)],
            ],
        ],
        'rows' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_tax_report error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']);
}
