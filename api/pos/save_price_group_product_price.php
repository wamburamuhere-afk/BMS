<?php
// scope-audit: skip — price group overrides are a global product-pricing lookup (no project/warehouse scope), same as tax_rates
/**
 * API: Set (or clear) one product's override price within a price group.
 * POST: price_group_id, product_id, price (blank/omitted = clear the
 *       override, product falls back to plain products.selling_price again).
 * Permission: canEdit('pos_config_settings').
 * Entitlement: Phase 14 (pos_upgrade_plan.md §8) — gated behind 'pos_advanced'.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())              { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos_advanced'))        { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Price groups are not included in your plan.')]); exit; }
if (!canEdit('pos_config_settings')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$price_group_id = (int)($_POST['price_group_id'] ?? 0);
$product_id     = (int)($_POST['product_id'] ?? 0);
$priceRaw       = $_POST['price'] ?? '';

if ($price_group_id <= 0 || $product_id <= 0) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

try {
    if ($priceRaw === '' || $priceRaw === null) {
        // Clear the override — product reverts to plain selling_price.
        $pdo->prepare("DELETE FROM product_price_group_prices WHERE price_group_id = ? AND product_id = ?")
            ->execute([$price_group_id, $product_id]);
        logActivity($pdo, $_SESSION['user_id'], "Cleared price-group override for product #$product_id (group #$price_group_id)");
        echo json_encode(['success' => true, 'message' => t('Price updated'), 'override_price' => null]);
        exit;
    }

    $price = (float)$priceRaw;
    if ($price < 0) {
        echo json_encode(['success' => false, 'message' => t('Price cannot be negative.')]);
        exit;
    }

    $pdo->prepare("
        INSERT INTO product_price_group_prices (product_id, price_group_id, price, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = NOW()
    ")->execute([$product_id, $price_group_id, $price]);

    logActivity($pdo, $_SESSION['user_id'], "Set price-group override for product #$product_id (group #$price_group_id) to " . number_format($price, 2));
    echo json_encode(['success' => true, 'message' => t('Price updated'), 'override_price' => $price]);

} catch (PDOException $e) {
    error_log('save_price_group_product_price error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
