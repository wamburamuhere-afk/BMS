<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/mobile_auth.php';
mobileBearerAuth();

if (!isAuthenticated()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canDelete('warehouses')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
if (empty($_SERVER['HTTP_AUTHORIZATION'])) csrf_check();

$body = $_POST;
if (empty($body)) { $raw = file_get_contents('php://input'); if ($raw) { $body = json_decode($raw, true) ?: []; } }

$warehouse_id = (int)($body['warehouse_id'] ?? 0);
if ($warehouse_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid warehouse ID']); exit; }

try {
    if (!isAdmin() && !userCan('warehouse', $warehouse_id)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied: this shop is not in your scope']);
        exit;
    }

    $check = $pdo->prepare("SELECT warehouse_name FROM warehouses WHERE warehouse_id = ? AND status != 'deleted'");
    $check->execute([$warehouse_id]);
    $name = $check->fetchColumn();
    if (!$name) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Shop not found']); exit; }

    // Guard: cannot delete a shop that has POS sales
    $hasSales = $pdo->prepare("SELECT 1 FROM pos_sales WHERE warehouse_id = ? AND sale_status NOT IN ('voided') LIMIT 1");
    $hasSales->execute([$warehouse_id]);
    if ($hasSales->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Cannot delete: shop has POS sales on record. Set it to Inactive instead.']);
        exit;
    }

    // Guard: cannot delete the only active shop
    $activeCount = $pdo->query("SELECT COUNT(*) FROM warehouses WHERE status = 'active' AND status != 'deleted'")->fetchColumn();
    if ((int)$activeCount <= 1) {
        http_response_code(409);
        echo json_encode(['success'=>false,'message'=>'Cannot delete the only active shop']);
        exit;
    }

    $pdo->prepare("UPDATE warehouses SET status = 'deleted', updated_at = NOW(), updated_by = ? WHERE warehouse_id = ?")
        ->execute([$_SESSION['user_id'], $warehouse_id]);

    logActivity($pdo, $_SESSION['user_id'], "Mobile: deleted shop #$warehouse_id ($name)");
    echo json_encode(['success'=>true,'message'=>'Shop deleted successfully']);
} catch (Throwable $e) {
    error_log('mobile/warehouses/delete.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
