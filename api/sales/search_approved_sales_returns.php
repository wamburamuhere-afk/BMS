<?php
// File: api/sales/search_approved_sales_returns.php
// scope-audit: skip — sales_returns scope is enforced via the joined sales_orders
// (so) alias with scopeFilterSqlNullable, same pattern as sales_returns.php.
// Select2 AJAX source: approved sales returns that do NOT yet have an active
// credit note (so a return can't be credited twice).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!canView('credit_notes')) { http_response_code(403); echo json_encode(['results' => []]); exit; }

$q           = trim($_GET['q'] ?? '');
$customer_id = isset($_GET['customer_id']) && $_GET['customer_id'] !== '' ? (int)$_GET['customer_id'] : 0;

try {
    global $pdo;
    $sql = "
        SELECT sr.sales_return_id, sr.return_number, sr.total_amount, sr.grand_total,
               c.customer_name
          FROM sales_returns sr
          LEFT JOIN customers c     ON sr.customer_id    = c.customer_id
          LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
         WHERE sr.status = 'approved'
           AND NOT EXISTS (
                SELECT 1 FROM credit_notes cn
                 WHERE cn.sales_return_id = sr.sales_return_id
                   AND cn.status NOT IN ('deleted','rejected','cancelled')
           )";
    $params = [];
    if ($customer_id > 0) {
        $sql .= " AND sr.customer_id = ?";
        $params[] = $customer_id;
    }
    if ($q !== '') {
        $sql .= " AND (sr.return_number LIKE ? OR c.customer_name LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    $sql .= scopeFilterSqlNullable('project', 'so');
    $sql .= " ORDER BY sr.return_date DESC, sr.sales_return_id DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = number_format((float)($r['grand_total'] ?: $r['total_amount']), 2);
        $results[] = [
            'id'   => (int)$r['sales_return_id'],
            'text' => $r['return_number'] . ' — ' . ($r['customer_name'] ?: 'Walk-in') . ' (TZS ' . $amt . ')',
        ];
    }
    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('search_approved_sales_returns error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
