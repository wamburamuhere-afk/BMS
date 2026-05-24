<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid method']); exit; }

if (!canEdit('do')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to change DO status']);
    exit;
}

try {
    $do_id     = intval($_POST['do_id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');
    if (!$do_id)      throw new Exception('DO ID is required.');
    if (!$new_status) throw new Exception('Status is required.');

    $stmt = $pdo->prepare("SELECT do_id, do_number, status FROM delivery_orders WHERE do_id = ?");
    $stmt->execute([$do_id]);
    $do = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$do) throw new Exception('Delivery Order not found.');

    // Enforce sequential workflow: draft → pending → approved
    $allowed = [
        'draft'   => 'pending',
        'pending' => 'approved',
    ];
    if ($do['status'] === 'approved') throw new Exception('This Delivery Order is already approved. No further status changes allowed.');
    if (!isset($allowed[$do['status']]) || $allowed[$do['status']] !== $new_status) {
        throw new Exception("Invalid status transition from '{$do['status']}' to '{$new_status}'.");
    }

    $pdo->prepare("UPDATE delivery_orders SET status=?, updated_by=? WHERE do_id=?")
        ->execute([$new_status, $_SESSION['user_id'], $do_id]);

    logActivity($pdo, $_SESSION['user_id'], "Changed DO #{$do['do_number']} status: {$do['status']} → {$new_status}");
    echo json_encode(['success'=>true, 'message'=>"DO #{$do['do_number']} status updated to " . strtoupper($new_status) . "."]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
