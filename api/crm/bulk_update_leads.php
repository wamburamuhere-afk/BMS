<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canEdit('crm_leads')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }
csrf_check();

$action   = trim($_POST['action'] ?? '');
$lead_ids = array_filter(array_map('intval', (array)($_POST['lead_ids'] ?? [])));
$value    = trim($_POST['value'] ?? '');

$allowed_actions = ['assign', 'stage', 'label', 'delete'];
if (!in_array($action, $allowed_actions, true)) { echo json_encode(['success' => false, 'message' => 'Invalid action']); exit; }
if (empty($lead_ids)) { echo json_encode(['success' => false, 'message' => 'No leads selected']); exit; }

// Cap bulk size
if (count($lead_ids) > 500) { echo json_encode(['success' => false, 'message' => 'Maximum 500 leads per bulk operation']); exit; }

try {
    // Validate all IDs belong to user's scope
    $scopeSql = scopeFilterSqlNullable('project', 'cl');
    $placeholders = implode(',', array_fill(0, count($lead_ids), '?'));
    $valid = $pdo->prepare("SELECT lead_id FROM crm_leads cl WHERE lead_id IN ($placeholders) AND cl.status != 'deleted' $scopeSql");
    $valid->execute($lead_ids);
    $validIds = $valid->fetchAll(PDO::FETCH_COLUMN);
    if (empty($validIds)) { echo json_encode(['success' => false, 'message' => 'No valid leads found in your scope']); exit; }

    $ph = implode(',', array_fill(0, count($validIds), '?'));
    $count = count($validIds);

    switch ($action) {
        case 'assign':
            if (!canEdit('crm_leads')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
            $user_id = intval($value) ?: null;
            if ($user_id) {
                $chk = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND is_active = 1");
                $chk->execute([$user_id]);
                if (!$chk->fetchColumn()) { echo json_encode(['success' => false, 'message' => 'User not found']); exit; }
            }
            $pdo->prepare("UPDATE crm_leads SET assigned_to = ?, updated_at = NOW() WHERE lead_id IN ($ph)")
                ->execute(array_merge([$user_id], $validIds));
            logActivity($pdo, $_SESSION['user_id'], "Bulk assigned $count lead(s) to user #$user_id");
            break;

        case 'stage':
            if (!canEdit('crm_pipeline')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
            $stage_id = intval($value);
            $stg = $pdo->prepare("SELECT stage_id, stage_name, is_won, is_lost FROM crm_pipeline_stages WHERE stage_id = ? AND status = 'active'");
            $stg->execute([$stage_id]);
            $stage = $stg->fetch(PDO::FETCH_ASSOC);
            if (!$stage) { echo json_encode(['success' => false, 'message' => 'Invalid stage']); exit; }

            // Get current stages for history
            $curStmt = $pdo->prepare("SELECT lead_id, pipeline_stage_id FROM crm_leads WHERE lead_id IN ($ph)");
            $curStmt->execute($validIds);
            $curStages = $curStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $pdo->prepare("UPDATE crm_leads SET pipeline_stage_id = ?, stage_entered = NOW(), updated_at = NOW() WHERE lead_id IN ($ph)")
                ->execute(array_merge([$stage_id], $validIds));

            // Log stage history for each
            $histStmt = $pdo->prepare("INSERT INTO crm_lead_stage_history (lead_id, from_stage_id, to_stage_id, changed_by) VALUES (?, ?, ?, ?)");
            foreach ($validIds as $lid) {
                $from = $curStages[$lid] ?? null;
                if ($from != $stage_id) {
                    $histStmt->execute([$lid, $from, $stage_id, $_SESSION['user_id']]);
                }
            }
            logActivity($pdo, $_SESSION['user_id'], "Bulk moved $count lead(s) to stage: {$stage['stage_name']}");
            break;

        case 'label':
            if (!canEdit('crm_leads')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
            $label_id = intval($value);
            if (!$label_id) { echo json_encode(['success' => false, 'message' => 'Invalid label']); exit; }
            $lbl = $pdo->prepare("SELECT label_id FROM crm_labels WHERE label_id = ? AND status = 'active'");
            $lbl->execute([$label_id]);
            if (!$lbl->fetchColumn()) { echo json_encode(['success' => false, 'message' => 'Label not found']); exit; }
            $ins = $pdo->prepare("INSERT IGNORE INTO crm_lead_labels (lead_id, label_id) VALUES (?, ?)");
            foreach ($validIds as $lid) { $ins->execute([$lid, $label_id]); }
            logActivity($pdo, $_SESSION['user_id'], "Bulk added label #$label_id to $count lead(s)");
            break;

        case 'delete':
            if (!canDelete('crm_leads')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }
            // Block deleting converted leads
            $convStmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE lead_id IN ($ph) AND converted = 1");
            $convStmt->execute($validIds);
            $converted = (int)$convStmt->fetchColumn();
            if ($converted > 0) { echo json_encode(['success' => false, 'message' => "$converted lead(s) are converted and cannot be deleted."]); exit; }
            $pdo->prepare("UPDATE crm_leads SET status = 'deleted', updated_at = NOW() WHERE lead_id IN ($ph)")
                ->execute($validIds);
            logActivity($pdo, $_SESSION['user_id'], "Bulk deleted $count lead(s)");
            break;
    }

    echo json_encode(['success' => true, 'message' => "$count lead(s) updated.", 'count' => $count]);
} catch (PDOException $e) {
    error_log('bulk_update_leads error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
