<?php
/**
 * API: Link/unlink one product to one Modifier Group (single-pair toggle,
 * used from the group's own "Manage Products" modal). Deliberately NOT a
 * full-replace like api/restaurant/save_product_modifier_links.php — that
 * one replaces a PRODUCT's entire group list from the product-edit form;
 * this one only ever touches the one (group_id, product_id) pair so
 * toggling a product here can never clobber that product's other links.
 * POST: group_id, product_id, linked (1|0)
 * Gate: restaurant_pos + canEdit.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/restaurant_scope.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!restaurantSchemaReady($pdo)) { echo json_encode(['success' => false, 'message' => t('Restaurant module is being set up for your account — please check back shortly.')]); exit; }
if (!canEdit('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$group_id   = (int)($_POST['group_id'] ?? 0);
$product_id = (int)($_POST['product_id'] ?? 0);
$linked     = !empty($_POST['linked']);

$groupChk = $pdo->prepare("SELECT 1 FROM modifier_groups WHERE group_id = ?");
$groupChk->execute([$group_id]);
$prodChk = $pdo->prepare("SELECT product_name FROM products WHERE product_id = ?");
$prodChk->execute([$product_id]);
$productName = $prodChk->fetchColumn();
if (!$groupChk->fetchColumn() || $productName === false) {
    echo json_encode(['success' => false, 'message' => t('Group or product not found.')]);
    exit;
}

if ($linked) {
    // product_modifier_groups has no UNIQUE(product_id, group_id) constraint
    // to lean on (confirmed by direct schema read), so check-then-insert
    // rather than INSERT IGNORE to avoid duplicate link rows on a re-toggle.
    $exists = $pdo->prepare("SELECT 1 FROM product_modifier_groups WHERE product_id = ? AND group_id = ?");
    $exists->execute([$product_id, $group_id]);
    if (!$exists->fetchColumn()) {
        $pdo->prepare("INSERT INTO product_modifier_groups (product_id, group_id, created_at) VALUES (?, ?, NOW())")
            ->execute([$product_id, $group_id]);
    }
} else {
    $pdo->prepare("DELETE FROM product_modifier_groups WHERE product_id = ? AND group_id = ?")
        ->execute([$product_id, $group_id]);
}

logActivity($pdo, $_SESSION['user_id'], ($linked ? 'Linked' : 'Unlinked') . " product \"$productName\" " . ($linked ? 'to' : 'from') . " modifier group #$group_id");
echo json_encode(['success' => true, 'message' => t('Product links updated.')]);
