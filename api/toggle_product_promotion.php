<?php
/**
 * api/toggle_product_promotion.php
 *
 * Phase 25 (pos_upgrade_plan.md §9) — deactivate (or reactivate) a product
 * promotion. Soft state flip only (status column), never a hard delete —
 * a past promo stays visible as history in the product-edit page's list.
 * POST: id, status ('active'|'inactive')
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

$id     = (int)($_POST['id'] ?? 0);
$status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';

if (!$id) { echo json_encode(['success' => false, 'message' => t('Invalid promotion ID')]); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT pp.product_id, p.product_name
        FROM product_promotions pp
        JOIN products p ON p.product_id = pp.product_id
        WHERE pp.promo_id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success' => false, 'message' => t('Promotion not found')]); exit; }

    assertScopeForRecord('products', 'product_id', (int)$row['product_id']);

    $pdo->prepare("UPDATE product_promotions SET status = ?, updated_at = NOW() WHERE promo_id = ?")->execute([$status, $id]);

    logActivity($pdo, $_SESSION['user_id'], "Set promotion #$id ($status) for product: {$row['product_name']}");
    echo json_encode(['success' => true, 'message' => $status === 'active' ? t('Promotion reactivated.') : t('Promotion deactivated.')]);

} catch (PDOException $e) {
    error_log('toggle_product_promotion: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
