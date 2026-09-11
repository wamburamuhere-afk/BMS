<?php
/**
 * API: List Modifier Groups (with their options nested)
 * GET — no params; modifier groups are a company-wide catalog concept, not
 * warehouse-scoped (same as price groups, tax rates).
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

$groups = $pdo->query("
    SELECT group_id, name, selection_type, min_select, max_select, is_required, status
    FROM modifier_groups
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

if (!empty($groups)) {
    $groupIds = array_column($groups, 'group_id');
    $ph = implode(',', array_fill(0, count($groupIds), '?'));
    $optStmt = $pdo->prepare("SELECT option_id, group_id, name, price_adjustment, status FROM modifier_options WHERE group_id IN ($ph) ORDER BY name");
    $optStmt->execute($groupIds);
    $optionsByGroup = [];
    foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $optionsByGroup[(int)$o['group_id']][] = $o;
    }
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_modifier_groups WHERE group_id IN ($ph)");
    // Linked-product count per group individually below (small catalogs; simplicity over one clever query).
    foreach ($groups as &$g) {
        $g['options'] = $optionsByGroup[(int)$g['group_id']] ?? [];
        $linkedStmt = $pdo->prepare("SELECT COUNT(*) FROM product_modifier_groups WHERE group_id = ?");
        $linkedStmt->execute([$g['group_id']]);
        $g['linked_product_count'] = (int)$linkedStmt->fetchColumn();
    }
    unset($g);
}

echo json_encode(['success' => true, 'data' => $groups]);
