<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;
header('Content-Type: application/json');
if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Invalid method']); exit; }

if (!canEdit('dn')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to change DN status']);
    exit;
}

try {
    $delivery_id = intval($_POST['delivery_id'] ?? 0);
    $new_status  = trim($_POST['status'] ?? '');
    if (!$delivery_id) throw new Exception('DN ID is required.');
    if (!$new_status)  throw new Exception('Status is required.');

    $stmt = $pdo->prepare("SELECT delivery_id, delivery_number, status FROM deliveries WHERE delivery_id = ?");
    $stmt->execute([$delivery_id]);
    $dn = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$dn) throw new Exception('Delivery Note not found.');

    $allowed = [
        'draft'  => ['review', 'approved'],
        'review' => ['approved']
    ];

    if (!isset($allowed[$dn['status']]) || !in_array($new_status, $allowed[$dn['status']])) {
        throw new Exception("Invalid status transition from '{$dn['status']}' to '{$new_status}'.");
    }

    // Prepare snapshot data
    $user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $user_role = $_SESSION['user_role'] ?? 'Staff';
    
    $update_sql = "UPDATE deliveries SET status=?, updated_by=? ";
    $params = [$new_status, $_SESSION['user_id']];

    if ($new_status === 'review') {
        $update_sql .= ", reviewed_by_name = ?, reviewed_by_role = ?, reviewed_at = NOW() ";
        $params[] = $user_name;
        $params[] = $user_role;
    } elseif ($new_status === 'approved') {
        $update_sql .= ", approved_by_name = ?, approved_by_role = ?, approved_at = NOW(), approved_by = ? ";
        $params[] = $user_name;
        $params[] = $user_role;
        $params[] = $_SESSION['user_id'];
    }

    $update_sql .= " WHERE delivery_id=?";
    $params[] = $delivery_id;

    $pdo->prepare($update_sql)->execute($params);

    logActivity($pdo, $_SESSION['user_id'], "Changed DN #{$dn['delivery_number']} status: {$dn['status']} → {$new_status}");
    echo json_encode(['success'=>true, 'message'=>"DN #{$dn['delivery_number']} status updated to " . strtoupper($new_status) . "."]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
