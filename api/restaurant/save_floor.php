<?php
/**
 * API: Create/Update a Restaurant Floor
 * POST: floor_id (blank = create), warehouse_id, name, sort_order
 * Gate: restaurant_pos + canEdit('restaurant_pos').
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
if (isset($_SESSION['user_lang'])) {
    loadLanguage($_SESSION['user_lang']);
}
require_once __DIR__ . '/../../core/project_scope.php';

if (!isAuthenticated())         { http_response_code(401); echo json_encode(['success' => false, 'message' => t('Unauthorized')]); exit; }
if (!canView('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Restaurant POS is not included in your plan.')]); exit; }
if (!canEdit('restaurant_pos')) { http_response_code(403); echo json_encode(['success' => false, 'message' => t('Permission denied')]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => t('Method not allowed')]); exit; }
csrf_check();

global $pdo;

$floor_id     = (int)($_POST['floor_id'] ?? 0);
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$name         = trim($_POST['name'] ?? '');
$sort_order   = (int)($_POST['sort_order'] ?? 0);

if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}
if ($name === '') {
    echo json_encode(['success' => false, 'message' => t('Floor name is required.')]);
    exit;
}

if ($floor_id > 0) {
    // A floor must not be re-parented to a warehouse the current user
    // doesn't hold either — verify the row's EXISTING warehouse too.
    $existing = $pdo->prepare("SELECT warehouse_id FROM restaurant_floors WHERE floor_id = ?");
    $existing->execute([$floor_id]);
    $existingWid = $existing->fetchColumn();
    if ($existingWid === false || !userCan('warehouse', (int)$existingWid)) {
        echo json_encode(['success' => false, 'message' => t('Floor not found.')]);
        exit;
    }
    $pdo->prepare("UPDATE restaurant_floors SET warehouse_id = ?, name = ?, sort_order = ?, updated_at = NOW() WHERE floor_id = ?")
        ->execute([$warehouse_id, $name, $sort_order, $floor_id]);
    logActivity($pdo, $_SESSION['user_id'], "Updated restaurant floor: $name");
    $message = t('Floor updated successfully.');
} else {
    $pdo->prepare("INSERT INTO restaurant_floors (warehouse_id, name, sort_order, status, created_at, updated_at) VALUES (?, ?, ?, 'active', NOW(), NOW())")
        ->execute([$warehouse_id, $name, $sort_order]);
    $floor_id = (int)$pdo->lastInsertId();
    logActivity($pdo, $_SESSION['user_id'], "Created restaurant floor: $name");
    $message = t('Floor created successfully.');
}

echo json_encode(['success' => true, 'message' => $message, 'floor_id' => $floor_id]);
