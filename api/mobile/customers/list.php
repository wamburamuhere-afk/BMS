<?php
// scope-audit: skip — customers list; project scope applied via scopeFilterSqlNullable
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('customers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

try {
    $search  = trim($_GET['search'] ?? '');
    $limit   = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset  = max(0, (int)($_GET['offset'] ?? 0));
    $status  = $_GET['status'] ?? '';   // '' = all non-deleted, 'active', 'inactive', etc.

    $where  = ["c.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $like     = '%' . $search . '%';
        $where[]  = "(c.customer_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if (in_array($status, ['active','inactive','suspended','blacklisted'], true)) {
        $where[]  = "c.status = ?";
        $params[] = $status;
    }

    $scope    = scopeFilterSqlNullable('project', 'c');
    $whereSql = 'WHERE ' . implode(' AND ', $where) . $scope;

    $count = $pdo->prepare("SELECT COUNT(*) FROM customers c $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.customer_id, c.customer_code, c.customer_name, c.phone, c.email,
               c.address, c.city, c.customer_type, c.status, c.credit_limit,
               c.notes, c.created_at, c.updated_at
          FROM customers c
        $whereSql
         ORDER BY c.customer_name ASC
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
    error_log('mobile/customers/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
