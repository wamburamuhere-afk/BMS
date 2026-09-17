<?php
/**
 * API: revoke a warehouse's public shop-catalog link — clears the stored
 * hash, so the link stops working immediately (shop_catalog.php's lookup
 * simply finds no matching warehouse). No token is accepted or needed here;
 * this is an authenticated admin action, not something reachable via the
 * public link itself.
 *
 * POST: warehouse_id
 * Permission: canEdit('warehouses') + userCan('warehouse', $warehouse_id)
 * Simple POS only (this whole feature is).
 */
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

require_once __DIR__ . '/../../core/warehouse_scope.php';
require_once __DIR__ . '/../../core/pos_nav.php';

header('Content-Type: application/json');

if (!isAuthenticated())      { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canEdit('warehouses'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if (!posSimpleModeEnabled()) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('This view is only available in Simple POS mode.')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

$warehouseId = (int)($_POST['warehouse_id'] ?? 0);
if ($warehouseId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => t('A valid warehouse is required.')]);
    exit;
}
if (!userCan('warehouse', $warehouseId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

try {
    global $pdo;

    $pdo->prepare("
        UPDATE warehouses
           SET public_catalog_token_hash = NULL, public_catalog_token_created_at = NULL
         WHERE warehouse_id = ?
    ")->execute([$warehouseId]);

    logActivity($pdo, $_SESSION['user_id'], "Revoked public shop-catalog link for warehouse #$warehouseId");
    logAudit($pdo, $_SESSION['user_id'], 'warehouse_public_catalog_link_revoked', [
        'entity_type' => 'warehouse',
        'entity_id'   => $warehouseId,
        'new_values'  => ['action' => 'revoked'],
    ]);

    echo json_encode(['success' => true, 'message' => t('Link revoked. It will no longer work.')]);
} catch (Throwable $e) {
    error_log('revoke_shop_catalog_link: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Server error.')]);
}
