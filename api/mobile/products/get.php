<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid product ID']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT p.product_id, p.product_name, p.sku, p.barcode, p.unit,
               p.selling_price, p.cost_price, p.purchase_price, p.current_stock,
               p.reorder_level, p.is_service, p.category_id, c.category_name,
               p.brand_id, b.brand_name, p.tax_rate_id, t.rate_name AS tax_name,
               p.status, p.description, p.image_url, p.min_selling_price,
               p.discount_rate, p.created_at, p.updated_at
          FROM products p
          LEFT JOIN categories c ON c.category_id = p.category_id
          LEFT JOIN brands     b ON b.brand_id     = p.brand_id
          LEFT JOIN tax_rates  t ON t.rate_id       = p.tax_rate_id
         WHERE p.product_id = ? AND p.status != 'deleted'
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

    echo json_encode(['success'=>true,'data'=>$row]);
} catch (Throwable $e) {
    error_log('mobile/products/get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
