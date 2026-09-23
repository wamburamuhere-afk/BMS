<?php
// scope-audit: skip — no project/warehouse scope on supplier creation
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/code_generator.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('suppliers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$supplier_name = trim($body['supplier_name'] ?? '');
if ($supplier_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Supplier name is required']); exit; }
if (mb_strlen($supplier_name) > 191) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Supplier name too long (max 191 characters)']); exit; }

$contact_person  = trim($body['contact_person']  ?? '');
$phone           = trim($body['phone']           ?? '');
$email           = trim($body['email']           ?? '');
$address         = trim($body['address']         ?? '');
$city            = trim($body['city']            ?? '');
$supplier_type   = trim($body['supplier_type']   ?? '');
$notes           = trim($body['notes']           ?? '');
$status          = in_array($body['status'] ?? 'active', ['active','inactive'], true) ? $body['status'] : 'active';

try {
    $supplier_code = nextCode($pdo, 'SUP');

    $stmt = $pdo->prepare("
        INSERT INTO suppliers
            (supplier_code, supplier_name, contact_person, phone, email, address, city,
             supplier_type, notes, status, created_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    $stmt->execute([
        $supplier_code, $supplier_name,
        $contact_person !== '' ? $contact_person : null,
        $phone          !== '' ? $phone          : null,
        $email          !== '' ? $email          : null,
        $address        !== '' ? $address        : null,
        $city           !== '' ? $city           : null,
        $supplier_type  !== '' ? $supplier_type  : null,
        $notes          !== '' ? $notes          : null,
        $status, $_SESSION['user_id'],
    ]);
    $supplier_id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Mobile: created supplier $supplier_name ($supplier_code)");

    echo json_encode([
        'success'       => true,
        'supplier_id'   => $supplier_id,
        'supplier_code' => $supplier_code,
        'supplier_name' => $supplier_name,
        'message'       => 'Supplier created successfully',
    ]);
} catch (Throwable $e) {
    error_log('mobile/suppliers/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
