<?php
// scope-audit: skip — product catalog is global; no project/warehouse scope at list level
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

try {
    $search     = trim($_GET['search']  ?? '');
    $limit      = max(1, min(500, (int)($_GET['limit'] ?? 50)));
    $offset     = max(0, (int)($_GET['offset'] ?? 0));
    $status     = $_GET['status']     ?? '';
    $category   = (int)($_GET['category_id'] ?? 0);
    // is_service: '0' = products only, '1' = services only, '' = both
    $is_service = $_GET['is_service'] ?? '';

    $where  = ["p.status != 'deleted'"];
    $params = [];

    if ($search !== '') {
        $like    = '%' . $search . '%';
        $where[] = "(p.product_name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if (in_array($status, ['active','inactive'], true)) {
        $where[] = "p.status = ?"; $params[] = $status;
    }
    if ($category > 0) {
        $where[] = "p.category_id = ?"; $params[] = $category;
    }
    if ($is_service === '1') {
        $where[] = "p.is_service = 1";
    } elseif ($is_service === '0') {
        $where[] = "p.is_service = 0";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.product_id, p.product_name, p.sku, p.barcode, p.unit,
               p.selling_price, p.cost_price, p.current_stock, p.reorder_level,
               p.is_service, p.category_id, c.category_name,
               p.status, p.description, p.image_url, p.created_at, p.updated_at
          FROM products p
          LEFT JOIN categories c ON c.category_id = p.category_id
        $whereSql
         ORDER BY p.product_name ASC
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
    error_log('mobile/products/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
