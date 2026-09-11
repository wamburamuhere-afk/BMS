<?php
/**
 * API: Create/Update a Kitchen Station
 * POST: station_id (blank = create), warehouse_id, name
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

$station_id   = (int)($_POST['station_id'] ?? 0);
$warehouse_id = (int)($_POST['warehouse_id'] ?? 0);
$name         = trim($_POST['name'] ?? '');

if ($warehouse_id <= 0 || !userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => t('Access denied: this warehouse is not in your assigned scope.')]);
    exit;
}
if ($name === '') {
    echo json_encode(['success' => false, 'message' => t('Station name is required.')]);
    exit;
}

if ($station_id > 0) {
    $existing = $pdo->prepare("SELECT warehouse_id FROM kitchen_stations WHERE station_id = ?");
    $existing->execute([$station_id]);
    $existingWid = $existing->fetchColumn();
    if ($existingWid === false || !userCan('warehouse', (int)$existingWid)) {
        echo json_encode(['success' => false, 'message' => t('Station not found.')]);
        exit;
    }
    $pdo->prepare("UPDATE kitchen_stations SET warehouse_id = ?, name = ?, updated_at = NOW() WHERE station_id = ?")
        ->execute([$warehouse_id, $name, $station_id]);
    logActivity($pdo, $_SESSION['user_id'], "Updated kitchen station: $name");
    $message = t('Kitchen station updated successfully.');
} else {
    $pdo->prepare("INSERT INTO kitchen_stations (warehouse_id, name, status, created_at, updated_at) VALUES (?, ?, 'active', NOW(), NOW())")
        ->execute([$warehouse_id, $name]);
    $station_id = (int)$pdo->lastInsertId();
    logActivity($pdo, $_SESSION['user_id'], "Created kitchen station: $name");
    $message = t('Kitchen station created successfully.');
}

echo json_encode(['success' => true, 'message' => $message, 'station_id' => $station_id]);
