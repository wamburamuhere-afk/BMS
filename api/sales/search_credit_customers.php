<?php
// File: api/sales/search_credit_customers.php
// scope-audit: skip — sales_returns scope is enforced via the joined sales_orders
// (so) alias with scopeFilterSqlNullable, same pattern as the sales return list.
// Select2 AJAX source for the Credit Note customer picker (§UI-3).
//
// INTELLIGENT: returns ONLY customers that have at least one APPROVED sales
// return with NO active credit note yet — i.e. customers you can actually raise a
// credit note for. Returns on open (caller uses minimumInputLength:0).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!canView('credit_notes')) { http_response_code(403); echo json_encode(['results' => []]); exit; }

$q          = trim($_GET['q'] ?? '');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : 0;

try {
    global $pdo;
    $sql = "
        SELECT DISTINCT c.customer_id, c.customer_name, c.company_name
          FROM customers c
          JOIN sales_returns sr ON sr.customer_id = c.customer_id AND sr.status = 'approved'
          LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
         WHERE c.status = 'active'
           AND NOT EXISTS (
                SELECT 1 FROM credit_notes cn
                 WHERE cn.sales_return_id = sr.sales_return_id
                   AND cn.status NOT IN ('deleted','rejected','cancelled')
           )";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (c.customer_name LIKE ? OR c.company_name LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%";
    }
    if ($project_id > 0 && userCan('project', $project_id)) {
        $sql .= " AND so.project_id = ?"; $params[] = $project_id;
    } else {
        $sql .= scopeFilterSqlNullable('project', 'so');
    }
    $sql .= " ORDER BY c.customer_name ASC LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $text = $r['customer_name'];
        if (!empty($r['company_name'])) $text .= ' — ' . $r['company_name'];
        $results[] = ['id' => (int)$r['customer_id'], 'text' => $text];
    }
    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('search_credit_customers error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
