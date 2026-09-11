<?php
/**
 * api/pos/get_available_serials.php
 *
 * Phase 26 (pos_upgrade_plan.md §9) — a product's in_stock serial numbers
 * for the given warehouse, for the POS quick-view modal's serial picker.
 * Gated pos_advanced (a genuinely upsell-shaped capacity feature, same
 * boundary reasoning as Phase 18/21/23) — the runtime double-check also
 * makes this fail closed (empty list) rather than exposing serial data to a
 * tenant whose entitlement has since been revoked.
 * GET: product_id, warehouse_id
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/warehouse_scope.php';
header('Content-Type: application/json');
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('pos')) { echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }

global $pdo;
$product_id   = (int)($_GET['product_id'] ?? 0);
$warehouse_id = (int)($_GET['warehouse_id'] ?? 0);
if (!$product_id || !$warehouse_id) { echo json_encode(['success' => true, 'data' => []]); exit; }

if (!function_exists('tenantFeatureEnabled') || !tenantFeatureEnabled('pos_advanced')) {
    echo json_encode(['success' => true, 'data' => []]); exit;
}

if (!userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT serial_number
        FROM product_serials
        WHERE product_id = ? AND warehouse_id = ? AND status = 'in_stock'
        ORDER BY created_at ASC, serial_id ASC
    ");
    $stmt->execute([$product_id, $warehouse_id]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
} catch (PDOException $e) {
    error_log('pos/get_available_serials error: ' . $e->getMessage());
    echo json_encode(['success' => true, 'data' => []]); // fail-open to an empty list, never blocks the sale flow
}
