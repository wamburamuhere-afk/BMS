<?php
// scope-audit: skip — product edit; products scope enforced via assertScopeForRecord below
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

require_once __DIR__ . '/../../../core/stock_ledger.php';
require_once __DIR__ . '/../../../core/stock_posting.php';
require_once __DIR__ . '/../../../core/code_generator.php';
require_once __DIR__ . '/_fields.php';

$fail = function (int $code, string $msg): void { http_response_code($code); echo json_encode(['success'=>false,'message'=>$msg]); exit; };

$product_id = (int)($body['product_id'] ?? 0);
if ($product_id <= 0) $fail(400, 'Invalid product ID');

$image_url = null;
try {
    assertScopeForRecord('products', 'product_id', $product_id);

    $check = $pdo->prepare("SELECT product_name, is_service, track_inventory, selling_price, cost_price, unit, image_url FROM products WHERE product_id = ?");
    $check->execute([$product_id]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    if (!$existing) $fail(404, 'Product not found');

    // product_name is optional on update — omitted means "keep".
    $product_name = array_key_exists('product_name', $body) ? trim((string)$body['product_name']) : $existing['product_name'];
    if ($product_name === '') $fail(422, 'Product name cannot be empty');

    try {
        $set = mobileProductOptionalFields($pdo, $body);
    } catch (InvalidArgumentException $ie) {
        $fail(422, $ie->getMessage());
    }
    $set['product_name'] = $product_name;

    if ($product_name !== $existing['product_name']) {
        $chk = $pdo->prepare("SELECT 1 FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND product_id <> ? LIMIT 1");
        $chk->execute([$product_name, $product_id]);
        if ($chk->fetchColumn()) $fail(409, "A product named \"$product_name\" already exists");
    }
    if (array_key_exists('sku', $body) && trim((string)$body['sku']) !== '') {
        $sku = trim((string)$body['sku']);
        $chk = $pdo->prepare("SELECT 1 FROM products WHERE (sku = ? OR product_code = ?) AND product_id <> ? LIMIT 1");
        $chk->execute([$sku, $sku, $product_id]);
        if ($chk->fetchColumn()) $fail(409, "SKU '$sku' is already in use");
        $set['sku'] = $sku; $set['product_code'] = $sku;
    }
    if (array_key_exists('barcode', $body)) {
        $bc = trim((string)$body['barcode']);
        if ($bc !== '') {
            $chk = $pdo->prepare("SELECT 1 FROM products WHERE barcode = ? AND product_id <> ? LIMIT 1");
            $chk->execute([$bc, $product_id]);
            if ($chk->fetchColumn()) $fail(409, "Barcode '$bc' is already in use");
        }
        $set['barcode'] = $bc !== '' ? $bc : null;
    }

    $selling = (float)$existing['selling_price'];
    if (array_key_exists('selling_price', $body)) {
        if (!is_numeric($body['selling_price']) || (float)$body['selling_price'] < 0) $fail(422, 'selling_price must be a non-negative number');
        $selling = (float)$body['selling_price'];
        $set['selling_price'] = $selling;
    }
    if (array_key_exists('cost_price', $body)) {
        if (!is_numeric($body['cost_price']) || (float)$body['cost_price'] < 0) $fail(422, 'cost_price must be a non-negative number');
        $set['cost_price'] = (float)$body['cost_price'];
        $set['purchase_price'] = (float)$body['cost_price'];
    }
    // Keep the price floor consistent with the web rule when price/discount change without an explicit floor.
    if (!isset($set['min_selling_price']) && (isset($set['selling_price']) || isset($set['discount_rate']))) {
        $disc = $set['discount_rate'] ?? (float)$pdo->query("SELECT COALESCE(discount_rate,0) FROM products WHERE product_id = " . $product_id)->fetchColumn();
        $set['min_selling_price'] = round($selling - ($selling * $disc / 100), 2);
    }
    if (isset($set['min_selling_price']) && $set['min_selling_price'] > $selling) $fail(422, 'min_selling_price cannot exceed selling_price');

    if (array_key_exists('reorder_level', $body)) $set['reorder_level'] = max(0, (float)$body['reorder_level']);
    if (isset($body['unit']) && trim((string)$body['unit']) !== '') $set['unit'] = trim((string)$body['unit']);
    if (array_key_exists('description', $body)) $set['description'] = trim((string)$body['description']) !== '' ? trim((string)$body['description']) : null;
    $_st = $body['status'] ?? null;
    if (in_array($_st, ['active', 'inactive', 'discontinued'], true)) $set['status'] = $_st;
    if (array_key_exists('track_inventory', $body) && !$existing['is_service']) {
        $set['track_inventory'] = !empty($body['track_inventory']) && $body['track_inventory'] !== 'false' ? 1 : 0;
    }

    // Stock edit → adjustment in one shop, same as the web product edit (movement + GL).
    $stockChange = null;
    if (array_key_exists('current_stock', $body) && !$existing['is_service']) {
        if (!is_numeric($body['current_stock']) || (float)$body['current_stock'] < 0) $fail(422, 'current_stock must be a non-negative number');
        $warehouse_id = (int)($body['warehouse_id'] ?? 0);
        if ($warehouse_id <= 0) {
            require_once __DIR__ . '/../../../core/warehouse_scope.php';
            $scoped = array_values(array_filter(warehousesForSelect($pdo), fn($w) => userCan('warehouse', (int)$w['warehouse_id'])));
            if (count($scoped) !== 1) $fail(422, 'warehouse_id is required to change stock (you have more than one shop)');
            $warehouse_id = (int)$scoped[0]['warehouse_id'];
        }
        if (!userCan('warehouse', $warehouse_id)) $fail(403, 'Access denied: this shop is not in your scope');
        $stockChange = [$warehouse_id, (float)$body['current_stock']];
    }

    try {
        $image_url = mobileProductImageUpload($pdo);
    } catch (InvalidArgumentException $ie) {
        $fail(422, $ie->getMessage());
    }
    if ($image_url !== null) $set['image_url'] = $image_url;

    $user_id = (int)$_SESSION['user_id'];
    $set['updated_by'] = $user_id;

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE products SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ", updated_at = NOW() WHERE product_id = ?")
        ->execute([...array_values($set), $product_id]);

    // Wholesale sent → the Wholesale price group POS charges from (0 removes it).
    if (array_key_exists('wholesale_price', $set)) {
        require_once __DIR__ . '/../../../core/pos_price_groups.php';
        syncWholesaleGroupPrice($pdo, $product_id, (float)$set['wholesale_price']);
    }

    $stockResult = null;
    if ($stockChange) {
        [$wid, $newQty] = $stockChange;
        $cur = $pdo->prepare("SELECT COALESCE(stock_quantity,0) FROM product_stocks WHERE product_id = ? AND warehouse_id = ? FOR UPDATE");
        $cur->execute([$product_id, $wid]);
        $curQty = (float)($cur->fetchColumn() ?: 0);
        $diff = round($newQty - $curQty, 4);
        if (abs($diff) > 1e-9) {
            $type = $diff > 0 ? 'adjustment_in' : 'adjustment_out';
            $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, ?, 0)
                           ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity)")->execute([$product_id, $wid, $newQty]);
            $pdo->prepare("UPDATE products p SET p.stock_quantity = (SELECT COALESCE(SUM(stock_quantity),0) FROM product_stocks WHERE product_id = p.product_id),
                                                p.current_stock  = (SELECT COALESCE(SUM(stock_quantity),0) FROM product_stocks WHERE product_id = p.product_id)
                            WHERE p.product_id = ?")->execute([$product_id]);
            $ref = nextCode($pdo, 'ADJ');
            $cost = (float)($set['cost_price'] ?? $existing['cost_price']);
            $mid = recordStockMovement($pdo, [
                'product_id' => $product_id, 'movement_type' => $type, 'quantity' => abs($diff),
                'unit' => $set['unit'] ?? $existing['unit'], 'unit_cost' => $cost,
                'reference_type' => 'manual', 'reference_id' => $product_id, 'reference_number' => $ref,
                'warehouse_id' => $wid, 'stock_before' => $curQty, 'stock_after' => $newQty,
                'reason' => 'Stock adjustment via product edit', 'notes' => 'Mobile stock edit', 'created_by' => $user_id,
            ]);
            postStockAdjustmentGl($pdo, $mid, $diff, $type, $cost, null, $user_id, date('Y-m-d'), $ref);
            $stockResult = ['warehouse_id' => $wid, 'before' => $curQty, 'after' => $newQty, 'reference_number' => $ref];
        }
    }

    $pdo->commit();

    if ($image_url !== null && !empty($existing['image_url']) && str_starts_with($existing['image_url'], 'uploads/products/')) {
        @unlink(__DIR__ . '/../../../' . $existing['image_url']);
    }
    logActivity($pdo, $user_id, "Mobile: updated product #$product_id ($product_name)");
    echo json_encode(['success' => true, 'message' => 'Product updated successfully', 'stock_adjustment' => $stockResult, 'image_url' => $image_url]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($image_url && is_file(__DIR__ . '/../../../' . $image_url)) @unlink(__DIR__ . '/../../../' . $image_url);
    error_log('mobile/products/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
