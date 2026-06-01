<?php
// api/operations/dispose_asset.php
//
// Disposes an asset via the DisposalService (Asset Register & PPE Schedule,
// Phase 6). Snapshots cost + accumulated depreciation, computes gain/loss,
// flips status, and stops future depreciation.
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/asset_disposal_service.php';

global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('assets')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: you do not have permission to dispose assets']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$asset_id = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
if (!$asset_id) {
    echo json_encode(['success' => false, 'message' => 'Asset ID is required']);
    exit;
}

$result = disposeAsset($pdo, $asset_id, [
    'disposal_date' => $_POST['disposal_date'] ?? date('Y-m-d'),
    'method'        => $_POST['method'] ?? 'sold',
    'proceeds'      => $_POST['proceeds'] ?? 0,
    'notes'         => $_POST['notes'] ?? '',
], (int)($_SESSION['user_id'] ?? 0));

echo json_encode($result);
