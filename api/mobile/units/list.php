<?php
// scope-audit: skip — units are a global catalog
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

try {
    $rows = $pdo->query("SELECT unit_id, unit_code, unit_name FROM product_units WHERE status = 'active' OR status IS NULL ORDER BY unit_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['unit_id'] = (int)$r['unit_id'];
    unset($r);
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    error_log('api/mobile/units/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
