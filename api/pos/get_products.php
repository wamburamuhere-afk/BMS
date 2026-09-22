<?php
// scope-audit: skip — POS product search; products are global catalog, POS scope deferred to Phase G-2
/**
 * API: POS Products / Categories
 *
 * GET ?type=categories  (default) → active product categories for POS category chips / Flutter cache
 * GET ?type=inventory              → active non-service products with stock and cost price
 * GET ?type=product                → alias for inventory
 *
 * Optional params for inventory/product type:
 *   search        string  — filter by name, sku or barcode
 *   limit         int     — max rows (default 500, max 1000)
 *
 * Supports both web-session auth and mobile Bearer-token auth.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;

    $type   = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'categories';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $limit  = max(1, min(1000, (int)($_GET['limit'] ?? 500)));

    if ($type === 'categories') {
        $stmt = $pdo->prepare(
            "SELECT category_id, category_name, parent_id
               FROM categories
              WHERE status = 'active' AND type = 'product'
              ORDER BY category_name"
        );
        $stmt->execute();
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    } else {
        // inventory / product listing (GRN product picker, Flutter restock picker, etc.)
        $params = [];
        $where  = ["p.status = 'active'", "p.is_service = 0"];

        if ($search !== '') {
            $like     = '%' . $search . '%';
            $where[]  = "(p.product_name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "
            SELECT p.product_id, p.product_name, p.sku, p.barcode,
                   p.unit, p.selling_price, p.cost_price, p.purchase_price,
                   p.current_stock, p.is_service, p.category_id,
                   c.category_name
              FROM products p
              LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY p.product_name ASC
             LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

} catch (Exception $e) {
    error_log('api/pos/get_products.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
