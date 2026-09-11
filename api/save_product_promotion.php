<?php
/**
 * api/save_product_promotion.php
 *
 * Phase 25 (pos_upgrade_plan.md §9) — create a time-bound promotional price
 * for a product (product_promotions), resolved ahead of price-group tiers by
 * core/pos_price_groups.php::resolveGroupPrices(). Editing an existing promo
 * is intentionally not supported — a promo already live or already run is a
 * historical pricing fact; end it (toggle_product_promotion.php) and create a
 * new one instead, so nothing that already priced a sale gets silently
 * rewritten under it.
 * POST: product_id, price, starts_at, ends_at
 * Permission: canEdit('products')
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canEdit('products')) { echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

$product_id = (int)($_POST['product_id'] ?? 0);
$price      = (float)($_POST['price'] ?? 0);
$starts_at  = trim($_POST['starts_at'] ?? '');
$ends_at    = trim($_POST['ends_at'] ?? '');

if (!$product_id || $price <= 0 || $starts_at === '' || $ends_at === '') {
    echo json_encode(['success' => false, 'message' => t('A promo price and start/end dates are required.')]);
    exit;
}

$startsTs = strtotime($starts_at);
$endsTs   = strtotime($ends_at);
if ($startsTs === false || $endsTs === false || $endsTs <= $startsTs) {
    echo json_encode(['success' => false, 'message' => t('The promotion end date must be after its start date.')]);
    exit;
}

assertScopeForRecord('products', 'product_id', $product_id);

try {
    $stmt = $pdo->prepare("SELECT product_name, selling_price FROM products WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) { echo json_encode(['success' => false, 'message' => t('Product not found')]); exit; }

    $pdo->prepare("
        INSERT INTO product_promotions (product_id, price, starts_at, ends_at, status, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'active', ?, NOW(), NOW())
    ")->execute([$product_id, $price, date('Y-m-d H:i:s', $startsTs), date('Y-m-d H:i:s', $endsTs), $_SESSION['user_id']]);
    $promo_id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Added promo price " . number_format($price, 2) . " for product: {$product['product_name']} ("
        . date('Y-m-d H:i', $startsTs) . ' to ' . date('Y-m-d H:i', $endsTs) . ')');

    echo json_encode(['success' => true, 'message' => t('Promotion added.'), 'id' => $promo_id]);

} catch (PDOException $e) {
    error_log('save_product_promotion: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
