<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canDelete('crm_labels')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$label_id = intval($_POST['label_id'] ?? 0);
if (!$label_id) { echo json_encode(['success' => false, 'message' => 'Invalid label ID']); exit; }

try {
    $chk = $pdo->prepare("SELECT label_name FROM crm_labels WHERE label_id = ? AND status != 'deleted'");
    $chk->execute([$label_id]);
    $lbl = $chk->fetchColumn();
    if (!$lbl) { echo json_encode(['success' => false, 'message' => 'Label not found']); exit; }

    // Remove from all leads first
    $pdo->prepare("DELETE FROM crm_lead_labels WHERE label_id = ?")->execute([$label_id]);

    // Soft delete
    $pdo->prepare("UPDATE crm_labels SET status = 'deleted' WHERE label_id = ?")->execute([$label_id]);

    logActivity($pdo, $_SESSION['user_id'], "Deleted CRM label: $lbl");
    echo json_encode(['success' => true, 'message' => "Label \"$lbl\" deleted."]);
} catch (PDOException $e) {
    error_log('delete_label error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
