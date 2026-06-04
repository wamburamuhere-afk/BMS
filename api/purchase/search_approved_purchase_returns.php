<?php
// File: api/purchase/search_approved_purchase_returns.php
// scope-audit: skip — purchase_returns scope enforced via the joined purchase_orders
// (po) alias with scopeFilterSqlNullable, same pattern as the purchase return list.
// Select2 AJAX: approved purchase returns with no active debit note yet.
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!canView('debit_notes')) { http_response_code(403); echo json_encode(['results' => []]); exit; }

$q = trim($_GET['q'] ?? '');

try {
    global $pdo;
    $sql = "
        SELECT pr.purchase_return_id, pr.return_number, pr.total_amount, s.supplier_name
          FROM purchase_returns pr
          LEFT JOIN suppliers s       ON pr.supplier_id       = s.supplier_id
          LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
         WHERE pr.status = 'approved'
           AND NOT EXISTS (
                SELECT 1 FROM debit_notes dn
                 WHERE dn.purchase_return_id = pr.purchase_return_id
                   AND dn.status NOT IN ('deleted','rejected','cancelled')
           )";
    $params = [];
    if ($q !== '') {
        $sql .= " AND (pr.return_number LIKE ? OR s.supplier_name LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%";
    }
    $sql .= scopeFilterSqlNullable('project', 'po');
    $sql .= " ORDER BY pr.return_date DESC, pr.purchase_return_id DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $amt = number_format((float)$r['total_amount'], 2);
        $results[] = [
            'id'   => (int)$r['purchase_return_id'],
            'text' => $r['return_number'] . ' — ' . ($r['supplier_name'] ?: 'Supplier') . ' (TZS ' . $amt . ')',
        ];
    }
    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('search_approved_purchase_returns error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
