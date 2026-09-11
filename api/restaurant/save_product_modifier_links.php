<?php
/**
 * API: Replace a product's linked modifier groups (full-replace, same
 * pattern as user_projects.php's project-assignment save).
 * POST: product_id, group_ids[] (may be empty to unlink everything)
 * Gate: restaurant_pos + canEdit('pos') (this is edited from the product
 * form, which is gated by product permissions, not a settings screen —
 * canEdit('restaurant_pos') would incorrectly require the Restaurant
 * entitlement just to tag a product for kitchen routing on a hybrid
 * warehouse, so this checks the product-editing permission instead).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())    { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canEdit('products'))  { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$product_id = (int)($_POST['product_id'] ?? 0);
$group_ids  = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['group_ids'] ?? [])))));

$prodChk = $pdo->prepare("SELECT 1 FROM products WHERE product_id = ?");
$prodChk->execute([$product_id]);
if (!$prodChk->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => t('Product not found.')]);
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare("DELETE FROM product_modifier_groups WHERE product_id = ?")->execute([$product_id]);
    if (!empty($group_ids)) {
        $ins = $pdo->prepare("INSERT INTO product_modifier_groups (product_id, group_id, created_at) VALUES (?, ?, NOW())");
        foreach ($group_ids as $gid) { $ins->execute([$product_id, $gid]); }
    }
    $pdo->commit();
    logActivity($pdo, $_SESSION['user_id'], "Updated modifier-group links for product #$product_id (" . count($group_ids) . ' group(s))');
    echo json_encode(['success' => true, 'message' => t('Modifier groups updated successfully.')]);
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('save_product_modifier_links: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => t('Database error.')]);
}
