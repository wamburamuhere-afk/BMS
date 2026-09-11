<?php
/**
 * API: List products linked to a Modifier Group (for the group's "Manage
 * Products" modal on app/bms/restaurant/modifier_group.php — the reverse
 * lookup of api/restaurant/get_product_modifier_groups.php, which looks up
 * groups for a product rather than products for a group).
 * GET ?group_id=
 * Gate: restaurant_pos.
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

global $pdo;

$group_id = (int)($_GET['group_id'] ?? 0);
if ($group_id <= 0) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.product_id, p.product_name, p.sku
    FROM product_modifier_groups pmg
    JOIN products p ON p.product_id = pmg.product_id
    WHERE pmg.group_id = ?
    ORDER BY p.product_name
");
$stmt->execute([$group_id]);
echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
