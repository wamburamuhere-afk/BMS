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

// Self-heal DDL (outside any transaction — MySQL DDL causes implicit commit).
try {
    if (!$pdo->query("SHOW COLUMNS FROM products LIKE 'client_uuid'")->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN client_uuid VARCHAR(36) NULL");
        try { $pdo->exec("ALTER TABLE products ADD UNIQUE KEY ux_products_client_uuid (client_uuid)"); } catch (PDOException $_ddlE) {}
    }
} catch (PDOException $_ddlE) {}

// Parse idempotency key.
$client_uuid = '';
$rawUuid = trim($body['client_uuid'] ?? '');
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rawUuid)) {
    $client_uuid = $rawUuid;
}

// Idempotency pre-check.
if ($client_uuid !== '') {
    try {
        $dupChk = $pdo->prepare("SELECT product_id, product_name, sku, is_service FROM products WHERE client_uuid = ? LIMIT 1");
        $dupChk->execute([$client_uuid]);
        if ($dup = $dupChk->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(['success' => true, 'idempotent' => true, 'product_id' => (int)$dup['product_id'], 'product_name' => $dup['product_name'], 'sku' => $dup['sku'], 'is_service' => (bool)$dup['is_service'], 'message' => ($dup['is_service'] ? 'Service' : 'Product') . ' already exists.']);
            exit;
        }
    } catch (PDOException $_idemp) { $client_uuid = ''; }
}

$product_name  = trim($body['product_name']  ?? '');
if ($product_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Product name is required']); exit; }
if (mb_strlen($product_name) > 191) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Product name too long (max 191 characters)']); exit; }

$selling_price = (float)($body['selling_price'] ?? 0);
if ($selling_price < 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Selling price cannot be negative']); exit; }

$is_service    = !empty($body['is_service']) ? 1 : 0;
// Matches the web form: physical goods track stock unless explicitly turned off.
$track_inventory = $is_service ? 0 : (isset($body['track_inventory']) ? (int)!empty($body['track_inventory']) : 1);
$cost_price    = max(0, (float)($body['cost_price']    ?? 0));
// Opening stock (current_stock kept as an alias of initial_stock for existing app builds).
$opening_qty   = ($is_service || !$track_inventory) ? 0 : max(0, (float)($body['initial_stock'] ?? $body['current_stock'] ?? 0));
$warehouse_id  = (int)($body['warehouse_id'] ?? 0);
$reorder_level = max(0, (float)($body['reorder_level'] ?? 0));
$unit          = trim($body['unit']          ?? '');
$sku           = trim($body['sku']           ?? '');
$barcode       = trim($body['barcode']       ?? '');
$description   = trim($body['description']   ?? '');
$_st           = $body['status'] ?? 'active';
$status        = in_array($_st, ['active','inactive'], true) ? $_st : 'active';

require_once __DIR__ . '/../../../core/stock_ledger.php';
require_once __DIR__ . '/../../../core/stock_posting.php';
require_once __DIR__ . '/../../../core/stock_intake.php';
require_once __DIR__ . '/_fields.php';

$fail = function (int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
};

$dateOrNull = function (string $k) use ($body, $fail): ?string {
    $v = trim((string)($body[$k] ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $fail(422, "$k must be YYYY-MM-DD");
    return $v;
};
$mfg_date    = $dateOrNull('manufacturing_date');
$expiry_date = $dateOrNull('expiry_date');
$image_url   = null;

try {
    try {
        $extra = mobileProductOptionalFields($pdo, $body);
    } catch (InvalidArgumentException $ie) {
        $fail(422, $ie->getMessage());
    }
    // Same rule as the web: min selling price defaults to selling price less the discount rate.
    if (!isset($extra['min_selling_price'])) {
        $extra['min_selling_price'] = round($selling_price - ($selling_price * ($extra['discount_rate'] ?? 0) / 100), 2);
    }
    if ($extra['min_selling_price'] > $selling_price) $fail(422, 'min_selling_price cannot exceed selling_price');

    // Same duplicate rules as the web (api/create_product.php).
    $chk = $pdo->prepare("SELECT 1 FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) LIMIT 1");
    $chk->execute([$product_name]);
    if ($chk->fetchColumn()) $fail(409, "A product named \"$product_name\" already exists. Please use a different name.");

    if ($sku === '') {
        $sku = 'PROD' . time() . random_int(100, 999);
    }
    $chk = $pdo->prepare("SELECT 1 FROM products WHERE sku = ? OR product_code = ? LIMIT 1");
    $chk->execute([$sku, $sku]);
    if ($chk->fetchColumn()) $fail(409, "SKU '$sku' is already in use — please choose a different one");

    if ($barcode !== '') {
        $chk = $pdo->prepare("SELECT 1 FROM products WHERE barcode = ? LIMIT 1");
        $chk->execute([$barcode]);
        if ($chk->fetchColumn()) $fail(409, "Barcode '$barcode' is already in use");
    }

    // Opening stock needs a shop; resolve it the way quick_restock does.
    if ($opening_qty > 0) {
        require_once __DIR__ . '/../../../core/warehouse_scope.php';
        if ($warehouse_id <= 0) {
            $scoped = array_values(array_filter(warehousesForSelect($pdo), fn($w) => userCan('warehouse', (int)$w['warehouse_id'])));
            if (count($scoped) === 1) $warehouse_id = (int)$scoped[0]['warehouse_id'];
            else $fail(422, count($scoped) ? 'warehouse_id is required for opening stock (you have more than one shop)' : 'No shop is assigned to your account');
        } elseif (!userCan('warehouse', $warehouse_id)) {
            $fail(403, 'Access denied: this shop is not in your scope');
        }
    }

    try {
        $image_url = mobileProductImageUpload($pdo);
    } catch (InvalidArgumentException $ie) {
        $fail(422, $ie->getMessage());
    }

    $user_id = (int)$_SESSION['user_id'];
    $cols = [
        'client_uuid'     => $client_uuid ?: null,
        'product_name'    => $product_name,
        'sku'             => $sku,
        'product_code'    => $sku,
        'barcode'         => $barcode !== '' ? $barcode : null,
        'unit'            => $unit !== '' ? $unit : null,
        'selling_price'   => $selling_price,
        'cost_price'      => $cost_price,
        'purchase_price'  => $cost_price,
        'current_stock'   => 0,
        'reorder_level'   => $reorder_level,
        'is_service'      => $is_service,
        'track_inventory' => $track_inventory,
        'description'     => $description !== '' ? $description : null,
        'status'          => $status,
        'created_by'      => $user_id,
    ] + $extra;
    if ($image_url !== null) $cols['image_url'] = $image_url;

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO products (" . implode(', ', array_keys($cols)) . ", created_at) VALUES ("
        . implode(', ', array_fill(0, count($cols), '?')) . ", NOW())")->execute(array_values($cols));
    $product_id = (int)$pdo->lastInsertId();

    // Opening stock = real batch + stock movement + GL (Dr Inventory / Cr Opening Balance), as on the web.
    if ($opening_qty > 0) {
        $intake = receiveProductBatch($pdo, [
            'product_id'       => $product_id,
            'warehouse_id'     => $warehouse_id,
            'quantity'         => $opening_qty,
            'unit_cost'        => $cost_price,
            'write_batch'      => true,
            'selling_price'    => $selling_price,
            'wholesale_price'  => $extra['wholesale_price'] ?? null,
            'manufacturing_date' => $mfg_date,
            'expiry_date'      => $expiry_date,
            'movement_type'    => 'adjustment_in',
            'reference_type'   => 'manual',
            'reference_id'     => $product_id,
            'reference_number' => $sku,
            'movement_date'    => date('Y-m-d'),
            'created_by'       => $user_id,
            'notes'            => 'Initial product stock',
        ]);
        postStockAdjustmentGl($pdo, (int)$intake['movement_id'], $opening_qty, 'adjustment_in',
            $cost_price, null, $user_id, date('Y-m-d'), $sku);
    }

    $pdo->commit();

    $kind = $is_service ? 'service' : 'product';
    logActivity($pdo, $user_id, "Mobile: created $kind '$product_name' (ID $product_id)");

    echo json_encode([
        'success'         => true,
        'product_id'      => $product_id,
        'product_name'    => $product_name,
        'sku'             => $sku,
        'is_service'      => (bool)$is_service,
        'track_inventory' => (bool)$track_inventory,
        'current_stock'   => $opening_qty,
        'image_url'       => $image_url,
        'message'         => ucfirst($kind) . ' created successfully',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($image_url && is_file(__DIR__ . '/../../../' . $image_url)) @unlink(__DIR__ . '/../../../' . $image_url);
    error_log('mobile/products/create.php: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
