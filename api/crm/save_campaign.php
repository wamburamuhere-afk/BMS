<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}

csrf_check();

$campaign_id     = intval($_POST['campaign_id'] ?? 0);
$campaign_name   = trim($_POST['campaign_name'] ?? '');
$type            = trim($_POST['type'] ?? '');
$target_audience = trim($_POST['target_audience'] ?? '');
$start_date      = trim($_POST['start_date'] ?? '');
$end_date        = trim($_POST['end_date'] ?? '') ?: null;
$budget          = max(0, floatval($_POST['budget'] ?? 0));
$status          = trim($_POST['status'] ?? 'Planned');

$allowed_types    = ['Email', 'SMS', 'Social Media', 'Direct Call', 'Other'];
$allowed_statuses = ['Planned', 'Active', 'Completed', 'Cancelled', 'Paused'];

if ($campaign_name === '') {
    echo json_encode(['success' => false, 'message' => 'Campaign name is required']); exit;
}
if (!in_array($type, $allowed_types, true)) $type = 'Other';
if (!in_array($status, $allowed_statuses, true)) $status = 'Planned';
if ($start_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid start date']); exit;
}
if ($end_date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = null;
}

try {
    if ($campaign_id) {
        if (!canEdit('campaign_management')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
        }
        $chk = $pdo->prepare("SELECT campaign_id FROM marketing_campaigns WHERE campaign_id = ? AND is_deleted = 0");
        $chk->execute([$campaign_id]);
        if (!$chk->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Campaign not found']); exit;
        }
        $pdo->prepare("
            UPDATE marketing_campaigns SET
                campaign_name = ?, type = ?, target_audience = ?,
                start_date = ?, end_date = ?, budget = ?, status = ?
            WHERE campaign_id = ?
        ")->execute([$campaign_name, $type, $target_audience, $start_date, $end_date, $budget, $status, $campaign_id]);

        logActivity($pdo, $_SESSION['user_id'], 'Edit campaign', "User edited campaign: $campaign_name (ID $campaign_id)");
        echo json_encode(['success' => true, 'message' => "Campaign \"$campaign_name\" updated."]);

    } else {
        if (!canCreate('campaign_management')) {
            echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
        }
        $pdo->prepare("
            INSERT INTO marketing_campaigns
                (campaign_name, type, target_audience, start_date, end_date, budget, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$campaign_name, $type, $target_audience, $start_date, $end_date, $budget, $status, $_SESSION['user_id']]);

        $new_campaign_id = $pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], 'Create campaign', "User created a new campaign: $campaign_name (ID $new_campaign_id)");
        echo json_encode(['success' => true, 'message' => "Campaign \"$campaign_name\" created."]);
    }

} catch (PDOException $e) {
    error_log('save_campaign error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
