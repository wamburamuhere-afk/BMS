<?php
// scope-audit: skip — delegates to api/create_stock_adjustment.php (product + warehouse + project scope gates)
// Mobile wrapper for a stock adjustment: same code path as the web (stock movement,
// product_stocks, products.current_stock and the GL posting via postStockAdjustmentGl).
// Retry-safe with client_uuid.
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!isAdmin() && !canEdit('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();
mobileJsonBody();

$fail = function (int $code, string $msg): void { http_response_code($code); echo json_encode(['success'=>false,'message'=>$msg]); exit; };

$IN_TYPES  = ['adjustment_in', 'found'];
$OUT_TYPES = ['adjustment_out', 'damaged', 'expired', 'theft', 'correction'];

$product_id    = (int)($_POST['product_id'] ?? 0);
$warehouse_id  = (int)($_POST['warehouse_id'] ?? 0);
$quantity      = (float)($_POST['quantity'] ?? 0);
$movement_type = trim((string)($_POST['movement_type'] ?? ''));
$reason        = trim((string)($_POST['reason'] ?? ''));

if ($product_id <= 0)   $fail(422, 'product_id is required');
if ($quantity <= 0)     $fail(422, 'quantity must be greater than zero');
if (!in_array($movement_type, array_merge($IN_TYPES, $OUT_TYPES), true)) {
    $fail(422, 'movement_type must be one of: ' . implode(', ', array_merge($IN_TYPES, $OUT_TYPES)));
}
if ($reason === '')     $fail(422, 'reason is required');

if ($warehouse_id <= 0) {
    require_once __DIR__ . '/../../../core/warehouse_scope.php';
    $scoped = array_values(array_filter(warehousesForSelect($pdo), fn($w) => userCan('warehouse', (int)$w['warehouse_id'])));
    if (count($scoped) !== 1) $fail(422, count($scoped) ? 'warehouse_id is required (you have more than one shop)' : 'No shop is assigned to your account');
    $warehouse_id = (int)$scoped[0]['warehouse_id'];
}
if (!userCan('warehouse', $warehouse_id)) $fail(403, 'Access denied: this shop is not in your scope');

$p = $pdo->prepare("SELECT is_service, track_inventory FROM products WHERE product_id = ?");
$p->execute([$product_id]);
$prod = $p->fetch(PDO::FETCH_ASSOC);
if (!$prod) $fail(404, 'Product not found');
if (!empty($prod['is_service']) || (isset($prod['track_inventory']) && !$prod['track_inventory'])) {
    $fail(422, 'This product does not track stock');
}

if (in_array($movement_type, $OUT_TYPES, true)) {
    $s = $pdo->prepare("SELECT COALESCE(stock_quantity,0) - COALESCE(reserved_quantity,0) FROM product_stocks WHERE product_id = ? AND warehouse_id = ?");
    $s->execute([$product_id, $warehouse_id]);
    $available = (float)($s->fetchColumn() ?: 0);
    if ($quantity > $available + 1e-9) $fail(409, "Cannot remove $quantity — only $available available in this shop");
}

$_POST['product_id']    = $product_id;
$_POST['warehouse_id']  = $warehouse_id;
$_POST['quantity']      = $quantity;
$_POST['movement_type'] = $movement_type;
$_POST['reason']        = $reason;
$_POST['unit_cost']     = $_POST['unit_cost'] ?? 0;

mobileRun($pdo, 'stock/adjust', $_POST['client_uuid'] ?? null, function () {
    global $pdo;
    require __DIR__ . '/../../create_stock_adjustment.php';
});
