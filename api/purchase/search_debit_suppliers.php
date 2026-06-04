<?php
// File: api/purchase/search_debit_suppliers.php
// scope-audit: skip — purchase_returns scope is enforced via the joined purchase_orders
// (po) alias with scopeFilterSqlNullable, same pattern as the purchase return list.
// Select2 AJAX source for the Debit Note supplier picker (§UI-3).
//
// INTELLIGENT: returns ONLY suppliers that have at least one APPROVED purchase
// return with NO active debit note yet — i.e. suppliers you can actually raise a
// debit note for. Returns on open (caller uses minimumInputLength:0).
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['results' => []]); exit; }
if (!canView('debit_notes')) { http_response_code(403); echo json_encode(['results' => []]); exit; }

$q          = trim($_GET['q'] ?? '');
$project_id = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : 0;

try {
    global $pdo;
    $sql = "
        SELECT DISTINCT s.supplier_id, s.supplier_name
          FROM suppliers s
          JOIN purchase_returns pr ON pr.supplier_id = s.supplier_id AND pr.status = 'approved'
          LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
         WHERE s.status = 'active'
           AND NOT EXISTS (
                SELECT 1 FROM debit_notes dn
                 WHERE dn.purchase_return_id = pr.purchase_return_id
                   AND dn.status NOT IN ('deleted','rejected','cancelled')
           )";
    $params = [];
    if ($q !== '') { $sql .= " AND s.supplier_name LIKE ?"; $params[] = "%$q%"; }
    if ($project_id > 0 && userCan('project', $project_id)) {
        $sql .= " AND pr.project_id = ?"; $params[] = $project_id;
    } else {
        $sql .= scopeFilterSqlNullable('project', 'po');
    }
    $sql .= " ORDER BY s.supplier_name ASC LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = ['id' => (int)$r['supplier_id'], 'text' => $r['supplier_name']];
    }
    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    error_log('search_debit_suppliers error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
