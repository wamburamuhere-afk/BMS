<?php
// scope-audit: skip — no project/warehouse scope on customer creation
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
require_once __DIR__ . '/../../../core/code_generator.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canCreate('customers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

// Check if the idempotency column is available (migration may not have run on this tenant yet).
$hasClientUuid = false;
try {
    $r = $pdo->query("SHOW COLUMNS FROM customers LIKE 'client_uuid'");
    $hasClientUuid = (bool)($r && $r->fetch());
} catch (PDOException $_) {}

// Parse idempotency key and do duplicate check only when column exists.
$client_uuid = '';
if ($hasClientUuid) {
    $rawUuid = trim($body['client_uuid'] ?? '');
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rawUuid)) {
        $client_uuid = $rawUuid;
    }
    if ($client_uuid !== '') {
        try {
            $dupChk = $pdo->prepare("SELECT customer_id, customer_code, customer_name FROM customers WHERE client_uuid = ? LIMIT 1");
            $dupChk->execute([$client_uuid]);
            if ($dup = $dupChk->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode(['success' => true, 'idempotent' => true, 'customer_id' => (int)$dup['customer_id'], 'customer_code' => $dup['customer_code'], 'customer_name' => $dup['customer_name'], 'message' => 'Customer already exists.']);
                exit;
            }
        } catch (PDOException $_idemp) { $client_uuid = ''; }
    }
}

$customer_name = trim($body['customer_name'] ?? '');
if ($customer_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Customer name is required']); exit; }
if (mb_strlen($customer_name) > 191) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Customer name too long (max 191 characters)']); exit; }

$phone         = trim($body['phone']         ?? '');
$email         = trim($body['email']         ?? '');
$address       = trim($body['address']       ?? '');
$city          = trim($body['city']          ?? '');
$customer_type = in_array($body['customer_type'] ?? 'individual', ['individual','business'], true) ? $body['customer_type'] : 'individual';
$credit_limit  = max(0, (float)($body['credit_limit'] ?? 0));
$notes         = trim($body['notes']         ?? '');
$status        = in_array($body['status'] ?? 'active', ['active','inactive'], true) ? $body['status'] : 'active';

try {
    $customer_code = nextCode($pdo, 'CUST');

    if ($hasClientUuid) {
        $stmt = $pdo->prepare("
            INSERT INTO customers
                (client_uuid, customer_code, customer_name, phone, email, address, city,
                 customer_type, credit_limit, notes, status, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $client_uuid ?: null,
            $customer_code, $customer_name,
            $phone  !== '' ? $phone  : null,
            $email  !== '' ? $email  : null,
            $address!== '' ? $address: null,
            $city   !== '' ? $city   : null,
            $customer_type, $credit_limit,
            $notes  !== '' ? $notes  : null,
            $status, $_SESSION['user_id'],
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO customers
                (customer_code, customer_name, phone, email, address, city,
                 customer_type, credit_limit, notes, status, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([
            $customer_code, $customer_name,
            $phone  !== '' ? $phone  : null,
            $email  !== '' ? $email  : null,
            $address!== '' ? $address: null,
            $city   !== '' ? $city   : null,
            $customer_type, $credit_limit,
            $notes  !== '' ? $notes  : null,
            $status, $_SESSION['user_id'],
        ]);
    }
    $customer_id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Mobile: created customer $customer_name ($customer_code)");

    echo json_encode([
        'success'       => true,
        'customer_id'   => $customer_id,
        'customer_code' => $customer_code,
        'customer_name' => $customer_name,
        'message'       => 'Customer created successfully',
    ]);
} catch (Throwable $e) {
    error_log('mobile/customers/create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
