<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!canDelete('campaign_management')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}

csrf_check();

$campaign_id = intval($_POST['campaign_id'] ?? 0);
if (!$campaign_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid campaign ID']); exit;
}

try {
    // Block delete if any leads are still linked
    $chkLeads = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE campaign_id = ? AND status != 'deleted'");
    $chkLeads->execute([$campaign_id]);
    $linkedLeads = (int)$chkLeads->fetchColumn();

    if ($linkedLeads > 0) {
        echo json_encode([
            'success' => false,
            'message' => "Cannot delete: $linkedLeads lead(s) are linked to this campaign. Remove the campaign from those leads first.",
        ]); exit;
    }

    $chk = $pdo->prepare("SELECT campaign_name FROM marketing_campaigns WHERE campaign_id = ? AND is_deleted = 0");
    $chk->execute([$campaign_id]);
    $campaign = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        echo json_encode(['success' => false, 'message' => 'Campaign not found']); exit;
    }

    $pdo->prepare("UPDATE marketing_campaigns SET is_deleted = 1 WHERE campaign_id = ?")
        ->execute([$campaign_id]);

    logActivity($pdo, $_SESSION['user_id'], "Delete campaign", "deleted campaign \"{$campaign['campaign_name']}\" with id $campaign_id");

    echo json_encode(['success' => true, 'message' => "Campaign \"{$campaign['campaign_name']}\" deleted."]);

} catch (PDOException $e) {
    error_log('delete_campaign error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
