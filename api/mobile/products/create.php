<?php
// scope-audit: skip — product creation; global catalog, no project/warehouse scope
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$product_name  = trim($body['product_name']  ?? '');
if ($product_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Product name is required']); exit; }
if (mb_strlen($product_name) > 191) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Product name too long (max 191 characters)']); exit; }

$selling_price = (float)($body['selling_price'] ?? 0);
if ($selling_price < 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Selling price cannot be negative']); exit; }

$is_service    = !empty($body['is_service']) ? 1 : 0;
$cost_price    = max(0, (float)($body['cost_price']    ?? 0));
$current_stock = $is_service ? 0 : max(0, (float)($body['current_stock'] ?? 0));
$reorder_level = max(0, (float)($body['reorder_level'] ?? 0));
$unit          = trim($body['unit']          ?? '');
$sku           = trim($body['sku']           ?? '');
$barcode       = trim($body['barcode']       ?? '');
$description   = trim($body['description']   ?? '');
$category_id   = (int)($body['category_id']  ?? 0) ?: null;
$status        = in_array($body['status'] ?? 'active', ['active','inactive'], true) ? $body['status'] : 'active';

try {
    // Auto-generate SKU if not supplied
    if ($sku === '') {
        $sku = 'PROD' . time() . rand(100, 999);
    }

    // Duplicate SKU guard
    if ($sku !== '') {
        $dup = $pdo->prepare("SELECT 1 FROM products WHERE sku = ? AND status != 'deleted'");
        $dup->execute([$sku]);
        if ($dup->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['success'=>false,'message'=>"SKU '$sku' is already in use — please choose a different one"]);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO products
            (product_name, sku, barcode, unit, selling_price, cost_price, purchase_price,
             current_stock, reorder_level, is_service, category_id,
             description, status, created_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->execute([
        $product_name,
        $sku      !== '' ? $sku      : null,
        $barcode  !== '' ? $barcode  : null,
        $unit     !== '' ? $unit     : null,
        $selling_price, $cost_price, $cost_price,
        $current_stock, $reorder_level,
        $is_service, $category_id,
        $description !== '' ? $description : null,
        $status, $_SESSION['user_id'],
    ]);
    $product_id = (int)$pdo->lastInsertId();

    $kind = $is_service ? 'service' : 'product';
    logActivity($pdo, $_SESSION['user_id'], "Mobile: created $kind '$product_name' (ID $product_id)");

    echo json_encode([
        'success'      => true,
        'product_id'   => $product_id,
        'product_name' => $product_name,
        'sku'          => $sku,
        'is_service'   => (bool)$is_service,
        'message'      => ucfirst($kind) . ' created successfully',
    ]);
} catch (Throwable $e) {
    error_log('mobile/products/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
