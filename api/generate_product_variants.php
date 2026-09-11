<?php
// scope-audit: skip — bulk product creation API; product catalog is global (mirrors api/create_product.php)
/**
 * api/generate_product_variants.php
 *
 * Phase 31 (pos_upgrade_plan.md §8) — Product Variants (size/color matrix).
 *
 * POST: parent_product_id, attributes (JSON string, e.g. {"Size":["S","M","L"],
 * "Color":["Red","Blue"]}), base_price (optional), base_cost (optional).
 *
 * A variant is a normal row in `products` — this endpoint bulk-inserts the
 * cartesian product of the given attribute values as real product rows
 * (parent_product_id + variant_attributes set), reusing the parent's
 * category/brand/supplier/unit/tax/status. Every downstream system already
 * keys off product_id, so a variant works with batches/serials/combos/price
 * groups/GL posting with zero changes there — this endpoint's only job is
 * to create the rows.
 *
 * Re-running this against the same parent with the same attribute values is
 * safe: an exact-match combination already on file is skipped, not
 * duplicated (compares the normalized, key-sorted variant_attributes JSON).
 *
 * Gate: canView('pos_advanced') (variants are gated the same as loyalty/
 * multi-register — see feature_registry.php) + canCreate('products') (this
 * creates new product rows, same verb create_product.php itself requires).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
global $pdo;

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos_advanced')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Product variants are not included in your plan.')]); exit; }
if (!isAdmin() && !canCreate('products')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

$parent_id = (int)($_POST['parent_product_id'] ?? 0);
$attributesRaw = json_decode($_POST['attributes'] ?? '', true);
$base_price = ($_POST['base_price'] ?? '') !== '' ? (float)$_POST['base_price'] : null;
$base_cost  = ($_POST['base_cost']  ?? '') !== '' ? (float)$_POST['base_cost']  : null;

if ($parent_id <= 0 || !is_array($attributesRaw) || empty($attributesRaw)) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

// Normalize: attribute name (trimmed, non-empty) -> unique, trimmed, non-empty values.
$attributes = [];
foreach ($attributesRaw as $name => $values) {
    $name = trim((string)$name);
    if ($name === '' || !is_array($values)) continue;
    $vals = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $values), fn($v) => $v !== '')));
    if (!empty($vals)) $attributes[$name] = $vals;
}
if (empty($attributes)) {
    echo json_encode(['success' => false, 'message' => t('Add at least one attribute type with at least one value.')]);
    exit;
}

// Cartesian product, capped — a runaway matrix (e.g. 5 attributes x 10
// values each = 100,000 rows) is almost certainly a mistake, not intent.
$combinations = [[]];
foreach ($attributes as $name => $vals) {
    $next = [];
    foreach ($combinations as $combo) {
        foreach ($vals as $v) {
            $next[] = $combo + [$name => $v];
        }
    }
    $combinations = $next;
    if (count($combinations) > 200) {
        echo json_encode(['success' => false, 'message' => t('That would generate more than 200 variants — reduce the number of attributes/values and try again.')]);
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
$stmt->execute([$parent_id]);
$parent = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$parent) {
    echo json_encode(['success' => false, 'message' => t('Parent product not found.')]);
    exit;
}
if (!empty($parent['parent_product_id'])) {
    echo json_encode(['success' => false, 'message' => t('A variant cannot itself have variants — generate them from the top-level product.')]);
    exit;
}
if ((int)$parent['is_service'] === 1) {
    echo json_encode(['success' => false, 'message' => t('Services cannot have variants.')]);
    exit;
}
if (!empty($parent['is_combo'])) {
    echo json_encode(['success' => false, 'message' => t('A combo/bundle product cannot have variants.')]);
    exit;
}

// Normalize a combo's JSON the same deterministic way on both write and
// read (ksort) so a later exact-match skip-check is a plain string compare.
$normalize = function (array $combo): string {
    ksort($combo);
    return json_encode($combo);
};

$existingStmt = $pdo->prepare("SELECT variant_attributes FROM products WHERE parent_product_id = ?");
$existingStmt->execute([$parent_id]);
$existingNormalized = [];
foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
    $decoded = json_decode((string)$json, true);
    if (is_array($decoded)) $existingNormalized[$normalize($decoded)] = true;
}

$insCols = [
    'product_name', 'sku', 'barcode', 'description', 'category_id', 'brand_id', 'supplier_id', 'unit',
    'weight', 'dimensions', 'cost_price', 'selling_price', 'min_selling_price', 'wholesale_price',
    'tax_id', 'tax_rate', 'discount_rate', 'reorder_level', 'min_stock_level', 'max_stock_level',
    'image_url', 'status', 'is_service', 'is_taxable', 'track_inventory', 'barcode_symbology',
    'parent_product_id', 'variant_attributes', 'created_by',
];
$placeholders = ':' . implode(', :', $insCols);
$insStmt = $pdo->prepare("INSERT INTO products (" . implode(', ', $insCols) . ") VALUES ($placeholders)");

$nameCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))");

$pdo->beginTransaction();
try {
    $created = 0; $skippedExisting = 0; $skippedNameCollision = 0; $newIds = [];
    foreach ($combinations as $combo) {
        $normalized = $normalize($combo);
        if (isset($existingNormalized[$normalized])) { $skippedExisting++; continue; }

        $labelParts = [];
        foreach ($combo as $an => $av) { $labelParts[] = "$an: $av"; }
        $variantName = $parent['product_name'] . ' (' . implode(', ', $labelParts) . ')';

        $nameCheckStmt->execute([$variantName]);
        if ((int)$nameCheckStmt->fetchColumn() > 0) { $skippedNameCollision++; continue; }

        $insStmt->execute([
            'product_name'      => $variantName,
            'sku'               => null,
            'barcode'           => null,
            'description'       => $parent['description'],
            'category_id'       => $parent['category_id'],
            'brand_id'          => $parent['brand_id'],
            'supplier_id'       => $parent['supplier_id'],
            'unit'              => $parent['unit'],
            'weight'            => $parent['weight'],
            'dimensions'        => $parent['dimensions'],
            'cost_price'        => $base_cost ?? $parent['cost_price'],
            'selling_price'     => $base_price ?? $parent['selling_price'],
            'min_selling_price' => $parent['min_selling_price'],
            'wholesale_price'   => $parent['wholesale_price'],
            'tax_id'            => $parent['tax_id'],
            'tax_rate'          => $parent['tax_rate'],
            'discount_rate'     => $parent['discount_rate'],
            'reorder_level'     => $parent['reorder_level'],
            'min_stock_level'   => $parent['min_stock_level'],
            'max_stock_level'   => $parent['max_stock_level'],
            'image_url'         => $parent['image_url'],
            'status'            => 'active',
            'is_service'        => 0,
            'is_taxable'        => $parent['is_taxable'],
            'track_inventory'   => $parent['track_inventory'],
            'barcode_symbology' => $parent['barcode_symbology'],
            'parent_product_id' => $parent_id,
            'variant_attributes'=> json_encode($combo),
            'created_by'        => $_SESSION['user_id'],
        ]);
        $newIds[] = (int)$pdo->lastInsertId();
        $existingNormalized[$normalized] = true; // guards a duplicate combination within the SAME request
        $created++;
    }

    $pdo->commit();

    logActivity($pdo, $_SESSION['user_id'], "Generated $created product variant(s) for '{$parent['product_name']}' (#$parent_id)");

    $msg = sprintf(t('%d variant(s) created.'), $created);
    if ($skippedExisting > 0) $msg .= ' ' . sprintf(t('%d already existed and were skipped.'), $skippedExisting);
    if ($skippedNameCollision > 0) $msg .= ' ' . sprintf(t('%d skipped due to a product-name collision.'), $skippedNameCollision);

    echo json_encode(['success' => true, 'message' => $msg, 'created' => $created, 'product_ids' => $newIds]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('generate_product_variants: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
