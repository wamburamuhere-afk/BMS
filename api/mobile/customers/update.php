<?php
// scope-audit: skip — scope enforced via assertScopeForRecord before write
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canEdit('customers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$customer_id = (int)($body['customer_id'] ?? 0);
if ($customer_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid customer ID']); exit; }

$customer_name = trim($body['customer_name'] ?? '');
if ($customer_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Customer name is required']); exit; }

try {
    assertScopeForRecord('customers', 'customer_id', $customer_id);

    $check = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ? AND status != 'deleted'");
    $check->execute([$customer_id]);
    if (!$check->fetchColumn()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Customer not found']); exit; }

    $phone         = trim($body['phone']         ?? '');
    $email         = trim($body['email']         ?? '');
    $address       = trim($body['address']       ?? '');
    $city          = trim($body['city']          ?? '');
    $customer_type = in_array($body['customer_type'] ?? '', ['individual','business'], true) ? $body['customer_type'] : null;
    $credit_limit  = isset($body['credit_limit']) ? max(0, (float)$body['credit_limit']) : null;
    $notes         = trim($body['notes']         ?? '');
    $status        = in_array($body['status'] ?? '', ['active','inactive','suspended','blacklisted'], true) ? $body['status'] : null;

    $sets   = ['customer_name = ?', 'updated_at = NOW()', 'updated_by = ?'];
    $params = [$customer_name, $_SESSION['user_id']];

    if ($phone         !== '')   { $sets[] = 'phone = ?';         $params[] = $phone; }
    if ($email         !== '')   { $sets[] = 'email = ?';         $params[] = $email; }
    if ($address       !== '')   { $sets[] = 'address = ?';       $params[] = $address; }
    if ($city          !== '')   { $sets[] = 'city = ?';          $params[] = $city; }
    if ($customer_type !== null) { $sets[] = 'customer_type = ?'; $params[] = $customer_type; }
    if ($credit_limit  !== null) { $sets[] = 'credit_limit = ?';  $params[] = $credit_limit; }
    if ($notes         !== '')   { $sets[] = 'notes = ?';         $params[] = $notes; }
    if ($status        !== null) { $sets[] = 'status = ?';        $params[] = $status; }

    $params[] = $customer_id;
    $pdo->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE customer_id = ?")
        ->execute($params);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: updated customer #$customer_id ($customer_name)");
    echo json_encode(['success'=>true,'message'=>'Customer updated successfully']);
} catch (Throwable $e) {
    error_log('mobile/customers/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
