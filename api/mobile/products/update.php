<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canEdit('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$product_id = (int)($body['product_id'] ?? 0);
if ($product_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid product ID']); exit; }

$product_name = trim($body['product_name'] ?? '');
if ($product_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Product name is required']); exit; }

try {
    $check = $pdo->prepare("SELECT is_service FROM products WHERE product_id = ? AND status != 'deleted'");
    $check->execute([$product_id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

    $sets   = ['product_name = ?', 'updated_at = NOW()', 'updated_by = ?'];
    $params = [$product_name, $_SESSION['user_id']];

    // Only update fields that were explicitly sent
    if (array_key_exists('selling_price', $body)) {
        $sp = max(0, (float)$body['selling_price']);
        $sets[] = 'selling_price = ?'; $params[] = $sp;
        $sets[] = 'min_selling_price = ?'; $params[] = $sp; // reset floor to new price
    }
    if (array_key_exists('cost_price', $body)) {
        $sets[] = 'cost_price = ?';     $params[] = max(0, (float)$body['cost_price']);
        $sets[] = 'purchase_price = ?'; $params[] = max(0, (float)$body['cost_price']);
    }
    if (array_key_exists('current_stock', $body) && !$existing['is_service']) {
        $sets[] = 'current_stock = ?'; $params[] = max(0, (float)$body['current_stock']);
    }
    if (array_key_exists('reorder_level', $body)) {
        $sets[] = 'reorder_level = ?'; $params[] = max(0, (float)$body['reorder_level']);
    }
    if (isset($body['unit']) && trim($body['unit']) !== '') {
        $sets[] = 'unit = ?'; $params[] = trim($body['unit']);
    }
    if (isset($body['category_id'])) {
        $sets[] = 'category_id = ?'; $params[] = (int)$body['category_id'] ?: null;
    }
    if (isset($body['description'])) {
        $sets[] = 'description = ?'; $params[] = trim($body['description']) ?: null;
    }
    if (in_array($body['status'] ?? '', ['active','inactive'], true)) {
        $sets[] = 'status = ?'; $params[] = $body['status'];
    }

    $params[] = $product_id;
    $pdo->prepare("UPDATE products SET " . implode(', ', $sets) . " WHERE product_id = ?")
        ->execute($params);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: updated product #$product_id ($product_name)");
    echo json_encode(['success'=>true,'message'=>'Product updated successfully']);
} catch (Throwable $e) {
    error_log('mobile/products/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
