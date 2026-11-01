<?php
/**
 * API: quick Menu actions (restaurant_plan.md Phase 1).
 * POST: warehouse_id, product_id, action = available | unavailable | remove
 *   available / unavailable → products.is_available ("Available today")
 *   remove                 → products.is_menu_item = 0 (the product itself is kept, never deleted)
 * Gate: Restaurant POS + Smart POS on, shop is Restaurant/Hybrid, canEdit('restaurant_pos').
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/restaurant_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!canEdit('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$product_id   = (int)($_POST['product_id'] ?? 0);
$action       = (string)($_POST['action'] ?? '');

if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => wLabel('Access denied: this warehouse is not in your assigned scope.', 'Access denied: this shop is not in your assigned scope.')]);
    exit;
}
if (!restaurantShopActive($pdo, $warehouse_id)) {
    echo json_encode(['success' => false, 'message' => t('This shop is not set up as a restaurant.')]);
    exit;
}

$st = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ? AND is_menu_item = 1 AND status = 'active'");
$st->execute([$product_id]);
$name = $st->fetchColumn();
if ($name === false) { echo json_encode(['success' => false, 'message' => t('Menu item not found.')]); exit; }

$uid = (int)$_SESSION['user_id'];
if ($action === 'available' || $action === 'unavailable') {
    $val = $action === 'available' ? 1 : 0;
    $pdo->prepare("UPDATE products SET is_available = ?, updated_by = ? WHERE product_id = ?")->execute([$val, $uid, $product_id]);
    logActivity($pdo, $uid, ($val ? 'Menu item available: ' : 'Menu item sold out: ') . $name);
    echo json_encode(['success' => true, 'message' => $val ? t('Available today.') : t('Marked as sold out today.')]);
} elseif ($action === 'remove') {
    $pdo->prepare("UPDATE products SET is_menu_item = 0, updated_by = ? WHERE product_id = ?")->execute([$uid, $product_id]);
    logActivity($pdo, $uid, "Removed from menu: $name");
    echo json_encode(['success' => true, 'message' => t('Removed from the menu.')]);
} else {
    echo json_encode(['success' => false, 'message' => t('Unknown action.')]);
}
