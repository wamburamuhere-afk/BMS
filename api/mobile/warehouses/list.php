<?php
// scope-audit: skip — warehouse list filtered to user's scope via warehousesForSelect
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('warehouses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

try {
    $search  = trim($_GET['search'] ?? '');
    $limit   = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset  = max(0, (int)($_GET['offset'] ?? 0));
    $status  = $_GET['status'] ?? '';

    // Non-admins see only their scoped warehouses
    $scopedIds = null;
    if (!isAdmin()) {
        $scope = $_SESSION['scope'] ?? [];
        if (empty($scope['is_admin'])) {
            $granted = $scope['warehouses'] ?? [];
            if (!in_array('*', $granted, true)) {
                $scopedIds = array_map('intval', $granted);
                if (empty($scopedIds)) {
                    echo json_encode(['success'=>true,'total'=>0,'limit'=>$limit,'offset'=>$offset,'data'=>[]]);
                    exit;
                }
            }
        }
    }

    $where  = ["w.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $like    = '%' . $search . '%';
        $where[] = "(w.warehouse_name LIKE ? OR w.warehouse_code LIKE ?)";
        $params[] = $like; $params[] = $like;
    }
    if (in_array($status, ['active','inactive'], true)) {
        $where[] = "w.status = ?"; $params[] = $status;
    }
    if ($scopedIds !== null) {
        $ph      = implode(',', array_fill(0, count($scopedIds), '?'));
        $where[] = "w.warehouse_id IN ($ph)";
        $params  = array_merge($params, $scopedIds);
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM warehouses w $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT w.warehouse_id, w.warehouse_name, w.warehouse_code, w.address,
               w.city, w.phone, w.email, w.contact_person,
               w.pos_mode, w.status, w.is_primary, w.created_at, w.updated_at
          FROM warehouses w
        $whereSql
         ORDER BY w.is_primary DESC, w.warehouse_name ASC
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
    error_log('mobile/warehouses/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
