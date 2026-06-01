<?php
/**
 * api/assets/verify_asset.php
 *
 * Physical-verification lookup (Asset Register & PPE Schedule, Phase 8.4):
 * given a scanned/typed code, find the asset by qr_code or asset_code and
 * report presence. Logs a 'verify' audit entry on a match; flags codes that
 * are not registered.
 *
 * GET: code. Permission: canView('assets').
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/asset_audit_service.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('assets'))  { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode(['success'=>false,'found'=>false,'message'=>'No code provided']); exit; }

try {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT asset_id, asset_code, asset_name, category, status, location, `condition`
          FROM assets
         WHERE status != 'deleted' AND (qr_code = ? OR asset_code = ?)
         LIMIT 1
    ");
    $stmt->execute([$code, $code]);
    $asset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$asset) {
        echo json_encode(['success'=>true, 'found'=>false, 'code'=>$code,
            'message'=>"No registered asset matches '$code' (found-not-registered)."]);
        exit;
    }

    logAssetAudit($pdo, (int)$asset['asset_id'], 'verify', null, null,
        'Physically verified via code ' . $code, (int)($_SESSION['user_id'] ?? 0));

    echo json_encode(['success'=>true, 'found'=>true, 'asset'=>$asset]);
} catch (Throwable $e) {
    error_log('verify_asset error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false, 'message'=>'Database error']);
}
