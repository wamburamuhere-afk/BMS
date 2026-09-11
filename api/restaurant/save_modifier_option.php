<?php
/**
 * API: Create/Update a Modifier Option (also doubles as activate/deactivate
 * when option_id + status are posted without name/price_adjustment changes)
 * POST: option_id (blank = create), group_id, name, price_adjustment, status
 * Gate: restaurant_pos + canEdit.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!canEdit('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$option_id        = (int)($_POST['option_id'] ?? 0);
$group_id         = (int)($_POST['group_id'] ?? 0);
$name             = trim($_POST['name'] ?? '');
$price_adjustment = (float)($_POST['price_adjustment'] ?? 0);
$status           = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

$groupChk = $pdo->prepare("SELECT 1 FROM modifier_groups WHERE group_id = ?");
$groupChk->execute([$group_id]);
if (!$groupChk->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => t('Modifier group not found.')]);
    exit;
}
if ($name === '') {
    echo json_encode(['success' => false, 'message' => t('Option name is required.')]);
    exit;
}

if ($option_id > 0) {
    $pdo->prepare("UPDATE modifier_options SET group_id = ?, name = ?, price_adjustment = ?, status = ?, updated_at = NOW() WHERE option_id = ?")
        ->execute([$group_id, $name, $price_adjustment, $status, $option_id]);
    logActivity($pdo, $_SESSION['user_id'], "Updated modifier option: $name");
    $message = t('Modifier option updated successfully.');
} else {
    $pdo->prepare("INSERT INTO modifier_options (group_id, name, price_adjustment, status, created_at, updated_at) VALUES (?, ?, ?, 'active', NOW(), NOW())")
        ->execute([$group_id, $name, $price_adjustment]);
    $option_id = (int)$pdo->lastInsertId();
    logActivity($pdo, $_SESSION['user_id'], "Created modifier option: $name");
    $message = t('Modifier option created successfully.');
}

echo json_encode(['success' => true, 'message' => $message, 'option_id' => $option_id]);
