<?php
/**
 * api/assets/get_next_asset_code.php
 *
 * Returns the next available asset code for a category so the registration
 * form can show it live when a category is picked (document §3.3 — category
 * cascade auto-fills the code). Read-only; the real code is finalised on save.
 *
 * GET params:
 *   category_id — the chosen category
 *
 * Response: { success, code, prefix, sequence }
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/asset_code_service.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('assets')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$category_id = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;

try {
    global $pdo;
    $next = peekNextAssetCode($pdo, $category_id);
    echo json_encode([
        'success'  => true,
        'code'     => $next['code'],
        'prefix'   => $next['prefix'],
        'sequence' => $next['sequence'],
    ]);
} catch (Throwable $e) {
    error_log('get_next_asset_code error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
