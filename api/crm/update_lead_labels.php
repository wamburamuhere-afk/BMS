<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('crm_leads')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$lead_id    = intval($_POST['lead_id'] ?? 0);
$label_ids  = array_map('intval', (array)($_POST['label_ids'] ?? []));

if (!$lead_id) { echo json_encode(['success' => false, 'message' => 'lead_id required']); exit; }

try {
    // Validate lead
    $chk = $pdo->prepare("SELECT lead_id FROM crm_leads WHERE lead_id = ? AND status != 'deleted'");
    $chk->execute([$lead_id]);
    if (!$chk->fetchColumn()) { echo json_encode(['success' => false, 'message' => 'Lead not found']); exit; }

    // Replace all labels for this lead
    $pdo->prepare("DELETE FROM crm_lead_labels WHERE lead_id = ?")->execute([$lead_id]);
    if ($label_ids) {
        $ins = $pdo->prepare("INSERT IGNORE INTO crm_lead_labels (lead_id, label_id) VALUES (?, ?)");
        foreach ($label_ids as $lid) {
            if ($lid > 0) $ins->execute([$lead_id, $lid]);
        }
    }

    logActivity($pdo, $_SESSION['user_id'], "Updated labels on lead #$lead_id");
    echo json_encode(['success' => true, 'message' => 'Labels updated.']);
} catch (PDOException $e) {
    error_log('update_lead_labels error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
