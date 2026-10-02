<?php
// scope-audit: skip — brands are a global catalog
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

try {
    $rows = $pdo->query("SELECT brand_id, brand_name, website, description, status FROM brands WHERE status = 'active' ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['brand_id'] = (int)$r['brand_id'];
    unset($r);
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    error_log('api/mobile/brands/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
