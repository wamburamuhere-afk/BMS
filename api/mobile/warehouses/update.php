<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canEdit('warehouses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$warehouse_id = (int)($body['warehouse_id'] ?? 0);
if ($warehouse_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid warehouse ID']); exit; }

$warehouse_name = trim($body['warehouse_name'] ?? '');
if ($warehouse_name === '') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Shop name is required']); exit; }

try {
    if (!isAdmin() && !userCan('warehouse', $warehouse_id)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied: this shop is not in your scope']);
        exit;
    }

    $check = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ? AND status != 'deleted'");
    $check->execute([$warehouse_id]);
    if (!$check->fetchColumn()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Shop not found']); exit; }

    $sets   = ['warehouse_name = ?', 'updated_at = NOW()', 'updated_by = ?'];
    $params = [$warehouse_name, $_SESSION['user_id']];

    if (isset($body['address'])) { $sets[] = 'address = ?'; $params[] = trim($body['address']) ?: null; }
    if (isset($body['city']))    { $sets[] = 'city = ?';    $params[] = trim($body['city'])    ?: null; }
    if (isset($body['phone']))   { $sets[] = 'phone = ?';   $params[] = trim($body['phone'])   ?: null; }
    if (isset($body['email']))   { $sets[] = 'email = ?';   $params[] = trim($body['email'])   ?: null; }
    if (isset($body['contact_person'])) { $sets[] = 'contact_person = ?'; $params[] = trim($body['contact_person']) ?: null; }
    if (isset($body['notes']))   { $sets[] = 'notes = ?';   $params[] = trim($body['notes'])   ?: null; }
    if (in_array($body['pos_mode'] ?? '', ['retail','restaurant','hybrid'], true)) {
        $sets[] = 'pos_mode = ?'; $params[] = $body['pos_mode'];
    }
    if (in_array($body['status'] ?? '', ['active','inactive'], true)) {
        $sets[] = 'status = ?'; $params[] = $body['status'];
    }

    $params[] = $warehouse_id;
    $pdo->prepare("UPDATE warehouses SET " . implode(', ', $sets) . " WHERE warehouse_id = ?")
        ->execute($params);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: updated shop #$warehouse_id ($warehouse_name)");
    echo json_encode(['success'=>true,'message'=>'Shop updated successfully']);
} catch (Throwable $e) {
    error_log('mobile/warehouses/update.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
