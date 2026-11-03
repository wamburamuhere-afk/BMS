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

require_once __DIR__ . '/../../../core/product_delete.php';

try {
    // Same rule as the web app (api/delete_product.php, core/product_delete.php):
    // refused while any shop still holds stock; the delete keeps every past
    // sale, stock movement, batch and ledger entry. products.status ENUM has no
    // 'deleted', so this stays a real delete of the product row only.
    $pdo->beginTransaction();
    $check = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ? FOR UPDATE");
    $check->execute([$product_id]);
    $name = $check->fetchColumn();
    if (!$name) { $pdo->rollBack(); http_response_code(404); echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

    $pdo->prepare("SELECT stock_id FROM product_stocks WHERE product_id = ? FOR UPDATE")->execute([$product_id]);
    $blocker = productStockBlocker($pdo, $product_id, (string)$name);
    if ($blocker !== null) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>$blocker]);
        exit;
    }

    deleteProductKeepHistory($pdo, $product_id);
    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Mobile: deleted product #$product_id ($name)");
    echo json_encode(['success'=>true,'message'=>'Product deleted successfully']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('mobile/products/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
