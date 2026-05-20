<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('customers')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

csrf_check();

$attachment_id = intval($_POST['attachment_id'] ?? 0);
if (!$attachment_id) { echo json_encode(['success' => false, 'message' => 'Attachment ID is required']); exit; }

try {
    $stmt = $pdo->prepare("SELECT attachment_id, lpo_id, file_path FROM customer_lpo_attachments WHERE attachment_id = ?");
    $stmt->execute([$attachment_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { echo json_encode(['success' => false, 'message' => 'Attachment not found']); exit; }

    $pdo->prepare("DELETE FROM customer_lpo_attachments WHERE attachment_id = ?")->execute([$attachment_id]);
    $abs = ROOT_DIR . '/' . $row['file_path'];
    if (file_exists($abs)) @unlink($abs);

    logActivity($pdo, $_SESSION['user_id'], "Deleted attachment #{$attachment_id} from LPO ID {$row['lpo_id']}");
    echo json_encode(['success' => true, 'message' => 'Attachment removed.']);
} catch (PDOException $e) {
    error_log("delete_lpo_attachment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
