<?php
// File: api/delete_rfq_attachment.php
// scope-audit: skip — attachment on an RFQ; parent RFQ scope enforced by assertScopeForRecord in update_rfq.php + delete_rfq.php
require_once __DIR__ . '/../roots.php';
global $pdo;
header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    csrf_check();

    if (!canDelete('rfq')) {
        http_response_code(403);
        throw new Exception('Access Denied: you do not have permission to delete RFQ attachments');
    }

    $attachment_id = intval($_POST['attachment_id'] ?? 0);
    if (!$attachment_id) throw new Exception('Invalid attachment');

    // Fetch the attachment and verify the RFQ is still draft
    $stmt = $pdo->prepare("
        SELECT a.*, r.status, r.created_by
        FROM rfq_attachments a
        JOIN rfq r ON r.rfq_id = a.rfq_id
        WHERE a.attachment_id = ?
    ");
    $stmt->execute([$attachment_id]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$att) throw new Exception('Attachment not found');
    if ($att['status'] !== 'draft') throw new Exception('Cannot remove attachments from a non-draft RFQ');

    // Delete the physical file
    $file = __DIR__ . '/../' . $att['file_path'];
    if (file_exists($file)) @unlink($file);

    // Remove DB row
    $pdo->prepare("DELETE FROM rfq_attachments WHERE attachment_id = ?")->execute([$attachment_id]);

    logActivity($pdo, $_SESSION['user_id'], "Removed RFQ attachment: {$att['attachment_name']}");
    echo json_encode(['success' => true, 'message' => 'Attachment removed.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
