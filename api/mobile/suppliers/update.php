<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canEdit('suppliers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$supplier_id = (int)($body['supplier_id'] ?? 0);
if ($supplier_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid supplier ID']); exit; }

$supplier_name = trim($body['supplier_name'] ?? '');
if ($supplier_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Supplier name is required']); exit; }

try {
    $check = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
    $check->execute([$supplier_id]);
    if (!$check->fetchColumn()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Supplier not found']); exit; }

    $contact_person = trim($body['contact_person'] ?? '');
    $phone          = trim($body['phone']          ?? '');
    $email          = trim($body['email']          ?? '');
    $address        = trim($body['address']        ?? '');
    $city           = trim($body['city']           ?? '');
    $supplier_type  = trim($body['supplier_type']  ?? '');
    $notes          = trim($body['notes']          ?? '');
    $status         = in_array($body['status'] ?? '', ['active','inactive'], true) ? $body['status'] : null;

    $sets   = ['supplier_name = ?', 'updated_at = NOW()', 'updated_by = ?'];
    $params = [$supplier_name, $_SESSION['user_id']];

    if ($contact_person !== '') { $sets[] = 'contact_person = ?'; $params[] = $contact_person; }
    if ($phone          !== '') { $sets[] = 'phone = ?';          $params[] = $phone; }
    if ($email          !== '') { $sets[] = 'email = ?';          $params[] = $email; }
    if ($address        !== '') { $sets[] = 'address = ?';        $params[] = $address; }
    if ($city           !== '') { $sets[] = 'city = ?';           $params[] = $city; }
    if ($supplier_type  !== '') { $sets[] = 'supplier_type = ?';  $params[] = $supplier_type; }
    if ($notes          !== '') { $sets[] = 'notes = ?';          $params[] = $notes; }
    if ($status         !== null) { $sets[] = 'status = ?';       $params[] = $status; }

    $params[] = $supplier_id;
    $pdo->prepare("UPDATE suppliers SET " . implode(', ', $sets) . " WHERE supplier_id = ?")
        ->execute($params);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: updated supplier #$supplier_id ($supplier_name)");
    echo json_encode(['success'=>true,'message'=>'Supplier updated successfully']);
} catch (Throwable $e) {
    error_log('mobile/suppliers/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
