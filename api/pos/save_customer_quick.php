<?php
// scope-audit: skip — creates a new customer record (no project/warehouse scope on customers table)
/**
 * API: Quick-create a customer from the POS checkout screen
 * ----------------------------------------------------------------------------
 * Minimal form — name (required) + phone (optional) — enough to record the
 * buyer on a credit or named sale. Not a full customer form; the full edit
 * lives in the web admin.
 *
 * POST (form-encoded or JSON): customer_name, phone?
 * Permission: canCreate('customers')
 * Returns: customer_id, customer_code, customer_name, phone
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canCreate('customers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: you cannot create customers']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

// Accept both form-encoded and JSON body
$body = $_POST;
if (empty($body)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true) ?: [];
    }
}

$customer_name = trim($body['customer_name'] ?? '');
$phone         = trim($body['phone']         ?? '');

if ($customer_name === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
    exit;
}
if (mb_strlen($customer_name) > 191) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Customer name is too long (max 191 characters)']);
    exit;
}

try {
    global $pdo;

    require_once __DIR__ . '/../../core/code_generator.php';
    $customer_code = nextCode($pdo, 'CUST');

    $stmt = $pdo->prepare("
        INSERT INTO customers (
            customer_code, customer_name, phone,
            customer_type, status, created_at, created_by
        ) VALUES (?, ?, ?, 'individual', 'active', NOW(), ?)
    ");
    $stmt->execute([$customer_code, $customer_name, ($phone !== '' ? $phone : null), $_SESSION['user_id']]);

    $customer_id = (int)$pdo->lastInsertId();

    logActivity($pdo, $_SESSION['user_id'], "Quick-created customer: $customer_name ($customer_code) via mobile POS");

    echo json_encode([
        'success'       => true,
        'customer_id'   => $customer_id,
        'customer_code' => $customer_code,
        'customer_name' => $customer_name,
        'phone'         => $phone !== '' ? $phone : null,
    ]);

} catch (Throwable $e) {
    error_log('save_customer_quick.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
