<?php
/**
 * api/account/search_suppliers.php
 *
 * Select2 AJAX source for the Purchase Report supplier filter (show a few,
 * type to find more — §UI-3).
 *
 * Project-scoped (security.md §23): a non-admin only sees suppliers that
 * appear on purchase orders within their assigned projects (+ untagged),
 * via the purchase_orders.project_id relationship.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
require_once __DIR__ . '/../../core/project_scope.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['results' => []]);
    exit;
}
if (!canView('purchase_report')) {
    http_response_code(403);
    echo json_encode(['results' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');

try {
    global $pdo;
    $sql = "SELECT DISTINCT s.supplier_id, s.supplier_name
              FROM suppliers s
              JOIN purchase_orders po ON po.supplier_id = s.supplier_id
             WHERE s.status = 'active'";
    $params = [];
    if ($q !== '') {
        $sql     .= " AND s.supplier_name LIKE ?";
        $params[] = '%' . $q . '%';
    }
    $sql .= scopeFilterSqlNullable('project', 'po');
    $sql .= " ORDER BY s.supplier_name ASC LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results[] = ['id' => (int)$r['supplier_id'], 'text' => $r['supplier_name']];
    }
    echo json_encode(['results' => $results]);

} catch (Throwable $e) {
    error_log('search_suppliers error: ' . $e->getMessage());
    echo json_encode(['results' => []]);
}
