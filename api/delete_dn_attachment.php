<?php
// File: api/delete_dn_attachment.php
// Removes a single supplier-DN attachment from a draft/review Delivery Note.
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

if (!canDelete('dn')) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Access Denied: you do not have permission to delete DN attachments']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

try {
    $attachment_id = intval($_POST['attachment_id'] ?? 0);
    if ($attachment_id <= 0) throw new Exception('Attachment ID is required.');

    $stmt = $pdo->prepare("
        SELECT a.*, d.status, d.dn_number, d.delivery_number
        FROM delivery_attachments a
        JOIN deliveries d ON a.delivery_id = d.delivery_id
        WHERE a.attachment_id = ?
    ");
    $stmt->execute([$attachment_id]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$att) throw new Exception('Attachment not found.');
    assertScopeForRecord('deliveries', 'delivery_id', $att['delivery_id']);
    if ($att['status'] === 'approved') throw new Exception('Cannot change attachments on an approved Delivery Note.');

    $pdo->prepare("DELETE FROM delivery_attachments WHERE attachment_id = ?")->execute([$attachment_id]);

    $abs = __DIR__ . '/../' . $att['file_path'];
    if (is_file($abs)) @unlink($abs);

    logActivity($pdo, $_SESSION['user_id'],
        "Removed attachment '{$att['file_name']}' from DN #" . ($att['dn_number'] ?: $att['delivery_number']));

    echo json_encode(['success' => true, 'message' => 'Attachment removed.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
