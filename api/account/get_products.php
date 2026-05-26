<?php
// scope-audit: skip — product search/lookup helper for forms; product catalog is global
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$active_only = isset($_GET['active_only']) ? filter_var($_GET['active_only'], FILTER_VALIDATE_BOOLEAN) : true;

try {
    global $pdo;
    
    $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
    
    $query = "
        SELECT 
            p.product_id,
            p.product_name,
            p.sku,
            p.barcode,
            p.description,
            p.unit,
            p.selling_price,
            p.cost_price,
            p.purchase_price,
            p.tax_rate,
            p.is_service,
            " . ($warehouse_id > 0 ? "COALESCE(ps.stock_quantity, 0) as current_stock" : "p.current_stock") . ",
            c.category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
    ";
    
    if ($warehouse_id > 0) {
        $query .= " LEFT JOIN product_stocks ps ON p.product_id = ps.product_id AND ps.warehouse_id = ? ";
    }
    
    $query .= " WHERE 1=1 ";
    
    $params = [];
    if ($warehouse_id > 0) {
        $params[] = $warehouse_id;
    }
    
    // If warehouse is selected, we only want physical products that are in stock OR any service
    if ($warehouse_id > 0) {
        $query .= " AND (p.is_service = 1 OR ps.stock_quantity > 0) ";
    }

    if ($active_only) {
        $query .= " AND p.status = 'active'";
    }

    if (isset($_GET['is_service']) && $_GET['is_service'] !== '') {
        $query .= " AND p.is_service = ? ";
        $params[] = $_GET['is_service'] == 1 ? 1 : 0;
    }
    
    if (!empty($search)) {
        $query .= " AND (p.product_name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $query .= " ORDER BY p.product_name ASC LIMIT " . (int)$limit;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $products]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}