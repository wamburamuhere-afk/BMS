<?php
// scope-audit: skip — scope enforced via assertScopeForRecord before soft-delete
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canDelete('customers')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$customer_id = (int)($body['customer_id'] ?? 0);
if ($customer_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid customer ID']); exit; }

try {
    assertScopeForRecord('customers', 'customer_id', $customer_id);

    $check = $pdo->prepare("SELECT customer_name FROM customers WHERE customer_id = ? AND status != 'deleted'");
    $check->execute([$customer_id]);
    $name = $check->fetchColumn();
    if (!$name) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Customer not found']); exit; }

    // Guard: cannot delete a customer who has active sales or invoices
    $hasSales = $pdo->prepare("SELECT 1 FROM pos_sales WHERE customer_id = ? AND sale_status NOT IN ('voided') LIMIT 1");
    $hasSales->execute([$customer_id]);
    if ($hasSales->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Cannot delete: customer has POS sales on record']);
        exit;
    }

    $pdo->prepare("UPDATE customers SET status = 'deleted', updated_at = NOW(), updated_by = ? WHERE customer_id = ?")
        ->execute([$_SESSION['user_id'], $customer_id]);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: deleted customer #$customer_id ($name)");
    echo json_encode(['success'=>true,'message'=>'Customer deleted successfully']);
} catch (Throwable $e) {
    error_log('mobile/customers/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
