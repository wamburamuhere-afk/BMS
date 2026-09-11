<?php
/**
 * API: Get the modifier groups linked to a product
 * GET ?product_id=
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

$product_id = (int)($_GET['product_id'] ?? 0);
if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => t('Invalid request.')]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT mg.group_id, mg.name
    FROM product_modifier_groups pmg
    JOIN modifier_groups mg ON mg.group_id = pmg.group_id
    WHERE pmg.product_id = ?
    ORDER BY mg.name
");
$stmt->execute([$product_id]);
echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
