<?php
/**
 * api/get_product_promotions.php
 *
 * Phase 25 (pos_upgrade_plan.md §9) — list a product's promotional-price
 * rows (product_promotions) for the product-edit page's promotions manager.
 * GET: product_id
 * Permission: canView('products')
 */
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('products')) { echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

$product_id = (int)($_GET['product_id'] ?? 0);
if (!$product_id) { echo json_encode(['success' => false, 'message' => t('Invalid product ID')]); exit; }

assertScopeForRecord('products', 'product_id', $product_id);

try {
    $stmt = $pdo->prepare("
        SELECT promo_id, price, starts_at, ends_at, status,
               (status = 'active' AND starts_at <= NOW() AND ends_at >= NOW()) AS is_currently_active
        FROM product_promotions
        WHERE product_id = ?
        ORDER BY starts_at DESC
    ");
    $stmt->execute([$product_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['promo_id'] = (int)$r['promo_id'];
        $r['price'] = (float)$r['price'];
        $r['is_currently_active'] = (bool)$r['is_currently_active'];
    }
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    error_log('get_product_promotions: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
