<?php
/**
 * API: Create/Update a Modifier Group
 * POST: group_id (blank = create), name, selection_type (single|multiple),
 *       min_select, max_select, is_required
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

$group_id       = (int)($_POST['group_id'] ?? 0);
$name           = trim($_POST['name'] ?? '');
$selection_type = ($_POST['selection_type'] ?? 'single') === 'multiple' ? 'multiple' : 'single';
$min_select     = max(0, (int)($_POST['min_select'] ?? 0));
$max_select     = max(1, (int)($_POST['max_select'] ?? 1));
$is_required    = !empty($_POST['is_required']) ? 1 : 0;

if ($name === '') {
    echo json_encode(['success' => false, 'message' => t('Modifier group name is required.')]);
    exit;
}
if ($selection_type === 'single') { $min_select = min($min_select, 1); $max_select = 1; }
if ($min_select > $max_select) {
    echo json_encode(['success' => false, 'message' => t('Minimum selections cannot exceed maximum selections.')]);
    exit;
}

if ($group_id > 0) {
    $pdo->prepare("UPDATE modifier_groups SET name = ?, selection_type = ?, min_select = ?, max_select = ?, is_required = ?, updated_at = NOW() WHERE group_id = ?")
        ->execute([$name, $selection_type, $min_select, $max_select, $is_required, $group_id]);
    logActivity($pdo, $_SESSION['user_id'], "Updated modifier group: $name");
    $message = t('Modifier group updated successfully.');
} else {
    $pdo->prepare("INSERT INTO modifier_groups (name, selection_type, min_select, max_select, is_required, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'active', ?, NOW(), NOW())")
        ->execute([$name, $selection_type, $min_select, $max_select, $is_required, $_SESSION['user_id']]);
    $group_id = (int)$pdo->lastInsertId();
    logActivity($pdo, $_SESSION['user_id'], "Created modifier group: $name");
    $message = t('Modifier group created successfully.');
}

echo json_encode(['success' => true, 'message' => $message, 'group_id' => $group_id]);
