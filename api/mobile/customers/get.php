<?php
// scope-audit: skip — single customer fetch; scope enforced via assertScopeForRecord
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('customers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid customer ID']); exit; }

try {
    assertScopeForRecord('customers', 'customer_id', $id);

    $stmt = $pdo->prepare("
        SELECT customer_id, customer_code, customer_name, company_name, phone, mobile,
               email, address, city, state, country, postal_code,
               customer_type, status, credit_limit, notes,
               contact_person, website, tax_id, payment_terms, currency,
               created_at, updated_at
          FROM customers
         WHERE customer_id = ? AND status != 'deleted'
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Customer not found']); exit; }

    echo json_encode(['success'=>true,'data'=>$row]);
} catch (Throwable $e) {
    error_log('mobile/customers/get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
