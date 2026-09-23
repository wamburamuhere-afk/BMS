<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canDelete('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$product_id = (int)($body['product_id'] ?? 0);
if ($product_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid product ID']); exit; }

try {
    $check = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ? AND status != 'deleted'");
    $check->execute([$product_id]);
    $name = $check->fetchColumn();
    if (!$name) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

    // Guard: cannot delete a product that has POS sales
    $hasSales = $pdo->prepare("SELECT 1 FROM pos_sale_items si JOIN pos_sales s ON s.sale_id = si.sale_id WHERE si.product_id = ? AND s.sale_status NOT IN ('voided') LIMIT 1");
    $hasSales->execute([$product_id]);
    if ($hasSales->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Cannot delete: product has POS sales on record. Set it to Inactive instead.']);
        exit;
    }

    $pdo->prepare("UPDATE products SET status = 'deleted', updated_at = NOW(), updated_by = ? WHERE product_id = ?")
        ->execute([$_SESSION['user_id'], $product_id]);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: deleted product #$product_id ($name)");
    echo json_encode(['success'=>true,'message'=>'Product deleted successfully']);
} catch (Throwable $e) {
    error_log('mobile/products/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
