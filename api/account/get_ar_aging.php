<?php
/**
 * api/account/get_ar_aging.php
 *
 * AJAX data source for the Accounts-Receivable (Invoice) Aging report.
 * Buckets every outstanding customer invoice (balance_due > 0) by how many days
 * past its due date it is, as of a chosen date: Current / 1-30 / 31-60 / 61-90 / 90+.
 *
 * Aged from due_date (falls back to invoice_date when an invoice has no due date).
 * Project-scoped per security.md §23.
 *
 * Response:
 *   { success, as_of_date, summary{current,d1_30,d31_60,d61_90,over_90,total,count,customers},
 *     customers:[{customer_id,customer_name,current,...,total}], rows:[{...,bucket}] }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../../core/customer_advance.php';   // customerAdvanceAvailable (IN-7)

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

$as_of       = $_GET['as_of_date'] ?? date('Y-m-d');
$customer_id = (isset($_GET['customer_id']) && $_GET['customer_id'] !== '') ? (int)$_GET['customer_id'] : null;
$project_id  = (isset($_GET['project_id'])  && $_GET['project_id']  !== '') ? (int)$_GET['project_id']  : null;

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

    // Outstanding = any ISSUED invoice (not cancelled/draft/void) whose remaining
    // balance is still positive. The remaining balance is COMPUTED as
    // grand_total - paid_amount, NOT read from the denormalised balance_due column,
    // which can drift stale and silently hide real debt. This also guarantees a
    // partially-paid invoice keeps its unpaid remainder aged by its due date.
    $remaining = "GREATEST(i.grand_total - COALESCE(i.paid_amount, 0), 0)";
    $where  = ["$remaining > 0", "i.status NOT IN ('cancelled','draft','void')"];
    $params = [$as_of];   // first ? is the DATEDIFF as-of date
    if ($customer_id !== null) { $where[] = "i.customer_id = ?"; $params[] = $customer_id; }
    $scope = '';
    if ($project_id !== null) { $where[] = "i.project_id = ?"; $params[] = $project_id; }
    else                      { $scope  = scopeFilterSqlNullable('project', 'i'); }
    $where_sql = implode(' AND ', $where) . $scope;

    $stmt = $pdo->prepare("
        SELECT i.invoice_id,
               i.invoice_number,
               i.invoice_date,
               COALESCE(i.due_date, i.invoice_date)               AS due_date,
               i.grand_total,
               COALESCE(i.paid_amount, 0)                         AS paid_amount,
               $remaining                                         AS balance,
               i.customer_id,
               COALESCE(c.customer_name, 'Unknown')               AS customer_name,
               DATEDIFF(?, COALESCE(i.due_date, i.invoice_date))  AS days_overdue
          FROM invoices i
          LEFT JOIN customers c ON i.customer_id = c.customer_id
         WHERE $where_sql
         ORDER BY days_overdue DESC, balance DESC
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary  = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0, 'count' => 0];
    $byCust   = [];
    $rows     = [];

    foreach ($invoices as $inv) {
        $bal  = (float)$inv['balance'];
        $days = (int)$inv['days_overdue'];

        if     ($days <= 0)  $bucket = 'current';
        elseif ($days <= 30) $bucket = 'd1_30';
        elseif ($days <= 60) $bucket = 'd31_60';
        elseif ($days <= 90) $bucket = 'd61_90';
        else                 $bucket = 'over_90';

        $summary[$bucket] += $bal;
        $summary['total'] += $bal;
        $summary['count']++;

        $cid = (int)$inv['customer_id'];
        if (!isset($byCust[$cid])) {
            $byCust[$cid] = ['customer_id' => $cid, 'customer_name' => $inv['customer_name'],
                             'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0, 'total' => 0.0];
        }
        $byCust[$cid][$bucket] += $bal;
        $byCust[$cid]['total'] += $bal;

        $rows[] = [
            'invoice_number' => $inv['invoice_number'],
            'customer_id'    => $cid,
            'customer_name'  => $inv['customer_name'],
            'invoice_date'   => $inv['invoice_date'],
            'due_date'       => $inv['due_date'],
            'days_overdue'   => $days,
            'grand_total'    => (float)$inv['grand_total'],
            'paid_amount'    => (float)$inv['paid_amount'],
            'balance'        => $bal,
            'bucket'         => $bucket,
        ];
    }

    // IN-7 — annotate each customer with their unused advance/deposit (a credit that
    // offsets what they owe). 'net_due' = outstanding − deposit held; the buckets are
    // left untouched (deposits aren't aged against a specific invoice).
    $summary['deposits'] = 0.0;
    foreach ($byCust as &$c) {
        $dep = customerAdvanceAvailable($pdo, (int)$c['customer_id']);
        $c['deposit'] = $dep;
        $c['net_due'] = round($c['total'] - $dep, 2);
        $summary['deposits'] += $dep;
    }
    unset($c);
    $summary['deposits'] = round($summary['deposits'], 2);
    $summary['net_due']  = round($summary['total'] - $summary['deposits'], 2);

    // Largest balances first
    usort($byCust, fn($a, $b) => $b['total'] <=> $a['total']);
    $summary['customers'] = count($byCust);

    echo json_encode([
        'success'    => true,
        'as_of_date' => $as_of,
        'summary'    => $summary,
        'customers'  => array_values($byCust),
        'rows'       => $rows,
    ]);

} catch (Throwable $e) {
    error_log('get_ar_aging error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
