<?php
// scope-audit: skip — supplier list for Simple POS; no project scope on suppliers
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('suppliers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

try {
    $search = trim($_GET['search'] ?? '');
    $limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $status = $_GET['status'] ?? '';

    $where  = ["s.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $like    = '%' . $search . '%';
        $where[] = "(s.supplier_name LIKE ? OR s.phone LIKE ? OR s.email LIKE ? OR s.contact_person LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if (in_array($status, ['active','inactive'], true)) {
        $where[] = "s.status = ?"; $params[] = $status;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM suppliers s $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT s.supplier_id, s.supplier_code, s.supplier_name, s.contact_person,
               s.phone, s.email, s.address, s.city, s.supplier_type,
               s.status, s.notes, s.created_at, s.updated_at
          FROM suppliers s
        $whereSql
         ORDER BY s.supplier_name ASC
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
    error_log('mobile/suppliers/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
