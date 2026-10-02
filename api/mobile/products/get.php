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
    // Select only columns this tenant actually has (older schemas lack some).
    $have = array_flip($pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN));
    $want = ['product_id','product_name','sku','product_code','barcode','unit','selling_price','cost_price','purchase_price',
             'wholesale_price','min_selling_price','discount_rate','current_stock','reorder_level','min_stock_level','max_stock_level',
             'is_service','track_inventory','is_taxable','tax_id','tax_rate','category_id','brand_id','status','description',
             'image_url','manufacturer','model','weight','expiry_days','track_serials','is_combo','created_at','updated_at'];
    $cols = [];
    foreach ($want as $c) $cols[] = isset($have[$c]) ? "p.$c" : "NULL AS $c";

    $hasBrands = (bool)$pdo->query("SHOW TABLES LIKE 'brands'")->fetch();
    $hasTax    = (bool)$pdo->query("SHOW TABLES LIKE 'tax_rates'")->fetch();
    $sql = "SELECT " . implode(', ', $cols) . ", c.category_name"
         . ($hasBrands && isset($have['brand_id']) ? ", b.brand_name" : ", NULL AS brand_name")
         . ($hasTax && isset($have['tax_id']) ? ", t.rate_name AS tax_name" : ", NULL AS tax_name")
         . " FROM products p LEFT JOIN categories c ON c.category_id = p.category_id"
         . ($hasBrands && isset($have['brand_id']) ? " LEFT JOIN brands b ON b.brand_id = p.brand_id" : "")
         . ($hasTax && isset($have['tax_id']) ? " LEFT JOIN tax_rates t ON t.rate_id = p.tax_id" : "")
         . " WHERE p.product_id = ? AND p.status != 'deleted'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Product not found']); exit; }

    // Backwards-compatible alias for older app builds.
    $row['tax_rate_id'] = $row['tax_id'];

    require_once __DIR__ . '/../../../core/project_scope.php';
    $st = $pdo->prepare("SELECT ps.warehouse_id, w.warehouse_name, COALESCE(ps.stock_quantity,0) AS stock_quantity,
                                COALESCE(ps.reserved_quantity,0) AS reserved_quantity
                           FROM product_stocks ps JOIN warehouses w ON w.warehouse_id = ps.warehouse_id
                          WHERE ps.product_id = ?" . scopeFilterSqlNullable('warehouse', 'ps') . " ORDER BY w.warehouse_name");
    $st->execute([$id]);
    $row['stock_by_shop'] = array_map(fn($r) => [
        'warehouse_id' => (int)$r['warehouse_id'], 'warehouse_name' => $r['warehouse_name'],
        'stock_quantity' => (float)$r['stock_quantity'], 'reserved_quantity' => (float)$r['reserved_quantity'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['success'=>true,'data'=>$row]);
} catch (Throwable $e) {
    error_log('mobile/products/get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
