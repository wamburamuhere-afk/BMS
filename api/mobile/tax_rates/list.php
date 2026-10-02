<?php
// scope-audit: skip — tax rates are a global setting
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('products')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

try {
    $rows = $pdo->query("SELECT rate_id, rate_name, rate_percentage, tax_kind FROM tax_rates
                          WHERE status = 'active' OR status IS NULL ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['rate_id'] = (int)$r['rate_id']; $r['rate_percentage'] = (float)$r['rate_percentage']; }
    unset($r);
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    error_log('mobile/tax_rates/list.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
