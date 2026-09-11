<?php
/**
 * API: Create/Update a Restaurant Table
 * POST: table_id (blank = create), warehouse_id, floor_id, table_number, seats
 * Gate: restaurant_pos + canEdit.
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

$table_id     = (int)($_POST['table_id'] ?? 0);
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$floor_id     = (int)($_POST['floor_id'] ?? 0);
$table_number = trim($_POST['table_number'] ?? '');
$seats        = max(1, (int)($_POST['seats'] ?? 2));

if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}
if ($table_number === '') {
    echo json_encode(['success' => false, 'message' => t('Table number is required.')]);
    exit;
}
$floorChk = $pdo->prepare("SELECT 1 FROM restaurant_floors WHERE floor_id = ? AND warehouse_id = ?");
$floorChk->execute([$floor_id, $warehouse_id]);
if (!$floorChk->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => t('The selected floor does not belong to this warehouse.')]);
    exit;
}

if ($table_id > 0) {
    $existing = $pdo->prepare("SELECT warehouse_id FROM restaurant_tables WHERE table_id = ?");
    $existing->execute([$table_id]);
    $existingWid = $existing->fetchColumn();
    if ($existingWid === false || !userCan('warehouse', (int)$existingWid)) {
        echo json_encode(['success' => false, 'message' => t('Table not found.')]);
        exit;
    }
    $pdo->prepare("UPDATE restaurant_tables SET warehouse_id = ?, floor_id = ?, table_number = ?, seats = ?, updated_at = NOW() WHERE table_id = ?")
        ->execute([$warehouse_id, $floor_id, $table_number, $seats, $table_id]);
    logActivity($pdo, $_SESSION['user_id'], "Updated restaurant table: $table_number");
    $message = t('Table updated successfully.');
} else {
    $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'available', NOW(), NOW())")
        ->execute([$warehouse_id, $floor_id, $table_number, $seats]);
    $table_id = (int)$pdo->lastInsertId();
    logActivity($pdo, $_SESSION['user_id'], "Created restaurant table: $table_number");
    $message = t('Table created successfully.');
}

echo json_encode(['success' => true, 'message' => $message, 'table_id' => $table_id]);
