<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!canEdit('crm_pipeline')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit;
}

csrf_check();

$lead_id     = intval($_POST['lead_id']      ?? 0);
$new_stage_id = intval($_POST['new_stage_id'] ?? 0);
$lost_reason = trim($_POST['lost_reason']    ?? '');

if (!$lead_id || !$new_stage_id) {
    echo json_encode(['success' => false, 'message' => 'lead_id and new_stage_id are required']); exit;
}

require_once __DIR__ . '/recalculate_lead_score.php';

try {
    // Validate stage
    $stageStmt = $pdo->prepare("SELECT stage_id, stage_name, is_won, is_lost FROM crm_pipeline_stages WHERE stage_id = ? AND status = 'active'");
    $stageStmt->execute([$new_stage_id]);
    $stage = $stageStmt->fetch(PDO::FETCH_ASSOC);
    if (!$stage) {
        echo json_encode(['success' => false, 'message' => 'Invalid or inactive pipeline stage']); exit;
    }

    // Validate lead — also get current stage for history
    $leadStmt = $pdo->prepare("SELECT lead_id, lead_code, first_name, last_name, converted, pipeline_stage_id FROM crm_leads WHERE lead_id = ? AND status != 'deleted'");
    $leadStmt->execute([$lead_id]);
    $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lead) {
        echo json_encode(['success' => false, 'message' => 'Lead not found']); exit;
    }

    $from_stage_id = $lead['pipeline_stage_id'];

    // Build update fields
    $probability = null;
    if ($stage['is_won'])  $probability = 100;
    if ($stage['is_lost']) $probability = 0;

    $wonDate  = $stage['is_won']  ? date('Y-m-d') : null;
    $lostDate = $stage['is_lost'] ? date('Y-m-d') : null;

    if ($probability !== null) {
        $pdo->prepare("UPDATE crm_leads SET pipeline_stage_id = ?, probability = ?, lost_reason = ?,
                       won_date = COALESCE(won_date, ?), lost_date = COALESCE(lost_date, ?),
                       stage_entered = NOW(), updated_at = NOW() WHERE lead_id = ?")
            ->execute([$new_stage_id, $probability, ($stage['is_lost'] && $lost_reason ? $lost_reason : null),
                       $wonDate, $lostDate, $lead_id]);
    } else {
        $pdo->prepare("UPDATE crm_leads SET pipeline_stage_id = ?, stage_entered = NOW(), updated_at = NOW() WHERE lead_id = ?")
            ->execute([$new_stage_id, $lead_id]);
    }

    // Log stage history
    $pdo->prepare("INSERT INTO crm_lead_stage_history (lead_id, from_stage_id, to_stage_id, changed_by) VALUES (?, ?, ?, ?)")
        ->execute([$lead_id, $from_stage_id, $new_stage_id, $_SESSION['user_id']]);

    // Recalculate score
    $score = computeLeadScore($pdo, $lead_id);
    $pdo->prepare("UPDATE crm_leads SET lead_score = ? WHERE lead_id = ?")->execute([$score, $lead_id]);

    $full_name = trim($lead['first_name'] . ' ' . $lead['last_name']);
    logActivity($pdo, $_SESSION['user_id'], "Lead {$lead['lead_code']} ({$full_name}) moved to stage: {$stage['stage_name']}");

    echo json_encode([
        'success'    => true,
        'message'    => "Lead moved to {$stage['stage_name']}",
        'is_won'     => (bool)$stage['is_won'],
        'is_lost'    => (bool)$stage['is_lost'],
        'probability'=> $probability,
        'score'      => $score,
    ]);

} catch (PDOException $e) {
    error_log('move_lead_stage error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
