<?php
/**
 * api/account/get_ap_aging.php
 *
 * AJAX data source for the Accounts-Payable (Bill) Aging report.
 * Buckets every unpaid, approved supplier/sub-contractor bill by how long it has
 * been outstanding as of a chosen date: Current / 1-30 / 31-60 / 61-90 / 90+.
 *
 * BMS note: supplier_invoices carry NO due_date and no partial-payment tracking
 * (status flips straight to 'paid'). So outstanding = bills with status='approved',
 * the payable amount = amount - WHT withheld, and aging is measured from
 * date_raised. (Documented on the page.)
 *
 * Project-scoped per security.md §23.
 *
 * Response:
 *   { success, as_of_date, summary{current,d1_30,d31_60,d61_90,over_90,total,count,vendors},
 *     vendors:[{vendor_id,vendor_name,current,...,total}], rows:[{...,bucket}] }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('financial_reports')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$as_of      = $_GET['as_of_date'] ?? date('Y-m-d');
$vendor_id  = (isset($_GET['vendor_id']) && $_GET['vendor_id'] !== '') ? (int)$_GET['vendor_id'] : null;
$project_id = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of)) {
    echo json_encode(['success' => false, 'message' => 'Invalid as-of date']);
    exit;
}
if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your assigned scope.']);
    exit;
}

try {
    global $pdo;

    // Outstanding payable = approved (not yet paid) bills, net of any WHT withheld.
    $where  = ["si.status = 'approved'"];
    $params = [$as_of];   // first ? = the DATEDIFF as-of date
    if ($vendor_id !== null) { $where[] = "si.supplier_id = ?"; $params[] = $vendor_id; }
    $scope = '';
    if ($project_id !== null) { $where[] = "si.project_id = ?"; $params[] = $project_id; }
    else                      { $scope  = scopeFilterSqlNullable('project', 'si'); }
    $where_sql = implode(' AND ', $where) . $scope;

    $stmt = $pdo->prepare("
        SELECT si.id,
               si.invoice_ref,
               si.invoice_type,
               si.date_raised,
               si.amount,
               COALESCE(si.wht_amount, 0)                             AS wht_amount,
               (si.amount - COALESCE(si.wht_amount, 0))               AS balance,
               si.supplier_id                                         AS vendor_id,
               COALESCE(s.supplier_name, 'Unknown')                   AS vendor_name,
               DATEDIFF(?, si.date_raised)                            AS days_outstanding
          FROM supplier_invoices si
          LEFT JOIN suppliers s ON si.supplier_id = s.supplier_id
         WHERE $where_sql
         ORDER BY days_outstanding DESC, balance DESC
    ");
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary  = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0, 'count' => 0];
    $byVendor = [];
    $rows     = [];

    foreach ($bills as $b) {
        $bal  = (float)$b['balance'];
        $days = (int)$b['days_outstanding'];

        if     ($days <= 0)  $bucket = 'current';
        elseif ($days <= 30) $bucket = 'd1_30';
        elseif ($days <= 60) $bucket = 'd31_60';
        elseif ($days <= 90) $bucket = 'd61_90';
        else                 $bucket = 'over_90';

        $summary[$bucket] += $bal;
        $summary['total'] += $bal;
        $summary['count']++;

        $vid = (int)$b['vendor_id'];
        if (!isset($byVendor[$vid])) {
            $byVendor[$vid] = ['vendor_id' => $vid, 'vendor_name' => $b['vendor_name'],
                               'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];
        }
        $byVendor[$vid][$bucket] += $bal;
        $byVendor[$vid]['total'] += $bal;

        $rows[] = [
            'invoice_ref'  => $b['invoice_ref'],
            'invoice_type' => $b['invoice_type'],
            'vendor_id'    => $vid,
            'vendor_name'  => $b['vendor_name'],
            'date_raised'  => $b['date_raised'],
            'days'         => $days,
            'amount'       => (float)$b['amount'],
            'wht_amount'   => (float)$b['wht_amount'],
            'balance'      => $bal,
            'bucket'       => $bucket,
        ];
    }

    usort($byVendor, fn($a, $b) => $b['total'] <=> $a['total']);
    $summary['vendors'] = count($byVendor);

    echo json_encode([
        'success'    => true,
        'as_of_date' => $as_of,
        'summary'    => $summary,
        'vendors'    => array_values($byVendor),
        'rows'       => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_ap_aging error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
