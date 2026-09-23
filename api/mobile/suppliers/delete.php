<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canDelete('suppliers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$supplier_id = (int)($body['supplier_id'] ?? 0);
if ($supplier_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid supplier ID']); exit; }

try {
    $check = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
    $check->execute([$supplier_id]);
    $name = $check->fetchColumn();
    if (!$name) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Supplier not found']); exit; }

    $pdo->prepare("UPDATE suppliers SET status = 'deleted', updated_at = NOW(), updated_by = ? WHERE supplier_id = ?")
        ->execute([$_SESSION['user_id'], $supplier_id]);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: deleted supplier #$supplier_id ($name)");
    echo json_encode(['success'=>true,'message'=>'Supplier deleted successfully']);
} catch (Throwable $e) {
    error_log('mobile/suppliers/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
