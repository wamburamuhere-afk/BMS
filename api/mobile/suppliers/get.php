<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('suppliers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid supplier ID']); exit; }

try {
    $stmt = $pdo->prepare("
        SELECT supplier_id, supplier_code, supplier_name, company_name, contact_person,
               phone, mobile, email, address, city, state, country,
               supplier_type, status, credit_limit, notes,
               tax_id, vat_number, payment_terms, currency,
               bank_name, bank_account, created_at, updated_at
          FROM suppliers
         WHERE supplier_id = ? AND status != 'deleted'
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Supplier not found']); exit; }

    echo json_encode(['success'=>true,'data'=>$row]);
} catch (Throwable $e) {
    error_log('mobile/suppliers/get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
