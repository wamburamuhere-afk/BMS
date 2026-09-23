<?php
// scope-audit: skip — project scope applied via scopeFilterSqlNullable; warehouse scope applied below
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('expenses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

try {
    $search       = trim($_GET['search']   ?? '');
    $limit        = max(1, min(200, (int)($_GET['limit']  ?? 50)));
    $offset       = max(0, (int)($_GET['offset'] ?? 0));
    $date_from    = trim($_GET['date_from'] ?? '');
    $date_to      = trim($_GET['date_to']   ?? '');
    $status       = $_GET['status']          ?? '';
    $warehouse_id = (int)($_GET['warehouse_id'] ?? 0);

    $where  = ["e.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $like    = '%' . $search . '%';
        $where[] = "e.description LIKE ?"; $params[] = $like;
    }
    if ($date_from !== '') { $where[] = "e.expense_date >= ?"; $params[] = $date_from; }
    if ($date_to   !== '') { $where[] = "e.expense_date <= ?"; $params[] = $date_to; }
    if (in_array($status, ['pending','paid','approved'], true)) {
        $where[] = "e.status = ?"; $params[] = $status;
    }
    if ($warehouse_id > 0) {
        if (!userCan('warehouse', $warehouse_id)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Access denied: warehouse not in your scope']); exit; }
        $where[] = "e.warehouse_id = ?"; $params[] = $warehouse_id;
    }

    // Append scope clauses directly (they start with " AND " or are empty for admins)
    require_once __DIR__ . '/../../../core/warehouse_scope.php';
    $projectScope   = scopeFilterSqlNullable('project',   'e');
    $warehouseScope = ($warehouse_id > 0) ? '' : scopeFilterSqlNullable('warehouse', 'e');

    $whereSql = 'WHERE ' . implode(' AND ', $where) . $projectScope . $warehouseScope;

    $count = $pdo->prepare("SELECT COUNT(*) FROM expenses e $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT e.expense_id, e.expense_date, e.description, e.amount, e.status,
               e.warehouse_id, w.warehouse_name,
               e.paid_to_type, e.paid_to_id, e.notes,
               e.created_at, e.updated_at
          FROM expenses e
          LEFT JOIN warehouses w ON w.warehouse_id = e.warehouse_id
        $whereSql
         ORDER BY e.expense_date DESC, e.expense_id DESC
         LIMIT " . (int)$limit . " OFFSET " . (int)$offset
    );
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset,
        'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    error_log('mobile/expenses/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
