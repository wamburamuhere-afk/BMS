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

$action = trim($_POST['action'] ?? '');

// ── ADD ────────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    if (!canCreate('crm_pipeline')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
    }

    $stage_name = trim($_POST['stage_name'] ?? '');
    if ($stage_name === '') {
        echo json_encode(['success' => false, 'message' => 'Stage name is required']); exit;
    }

    $color    = trim($_POST['color'] ?? '#6c757d');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6c757d';

    $is_won  = !empty($_POST['is_won'])  ? 1 : 0;
    $is_lost = !empty($_POST['is_lost']) ? 1 : 0;

    // Only one Won and one Lost stage allowed
    if ($is_won) {
        $existing = (int)$pdo->query("SELECT COUNT(*) FROM crm_pipeline_stages WHERE is_won = 1 AND status = 'active'")->fetchColumn();
        if ($existing) { echo json_encode(['success' => false, 'message' => 'A "Won" stage already exists. Only one is allowed.']); exit; }
    }
    if ($is_lost) {
        $existing = (int)$pdo->query("SELECT COUNT(*) FROM crm_pipeline_stages WHERE is_lost = 1 AND status = 'active'")->fetchColumn();
        if ($existing) { echo json_encode(['success' => false, 'message' => 'A "Lost" stage already exists. Only one is allowed.']); exit; }
    }

    $max_order = (int)$pdo->query("SELECT COALESCE(MAX(stage_order), 0) FROM crm_pipeline_stages WHERE status = 'active'")->fetchColumn();

    $pdo->prepare("INSERT INTO crm_pipeline_stages (stage_name, stage_order, color, is_won, is_lost) VALUES (?, ?, ?, ?, ?)")
        ->execute([$stage_name, $max_order + 1, $color, $is_won, $is_lost]);

    logActivity($pdo, $_SESSION['user_id'], "Added pipeline stage: $stage_name");
    echo json_encode(['success' => true, 'message' => "Stage \"$stage_name\" added."]);
    exit;
}

// ── EDIT ───────────────────────────────────────────────────────────────────────
if ($action === 'edit') {
    if (!canEdit('crm_pipeline')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
    }

    $stage_id   = intval($_POST['stage_id'] ?? 0);
    $stage_name = trim($_POST['stage_name'] ?? '');
    if (!$stage_id || $stage_name === '') {
        echo json_encode(['success' => false, 'message' => 'Stage ID and name are required']); exit;
    }

    $stg = $pdo->prepare("SELECT * FROM crm_pipeline_stages WHERE stage_id = ? AND status = 'active'");
    $stg->execute([$stage_id]);
    $stg = $stg->fetch(PDO::FETCH_ASSOC);
    if (!$stg) { echo json_encode(['success' => false, 'message' => 'Stage not found']); exit; }

    $color   = trim($_POST['color'] ?? $stg['color']);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = $stg['color'];
    $is_won  = !empty($_POST['is_won'])  ? 1 : 0;
    $is_lost = !empty($_POST['is_lost']) ? 1 : 0;

    if ($is_won && !$stg['is_won']) {
        $existing = (int)$pdo->query("SELECT COUNT(*) FROM crm_pipeline_stages WHERE is_won = 1 AND status = 'active' AND stage_id != $stage_id")->fetchColumn();
        if ($existing) { echo json_encode(['success' => false, 'message' => 'A "Won" stage already exists.']); exit; }
    }
    if ($is_lost && !$stg['is_lost']) {
        $existing = (int)$pdo->query("SELECT COUNT(*) FROM crm_pipeline_stages WHERE is_lost = 1 AND status = 'active' AND stage_id != $stage_id")->fetchColumn();
        if ($existing) { echo json_encode(['success' => false, 'message' => 'A "Lost" stage already exists.']); exit; }
    }

    $pdo->prepare("UPDATE crm_pipeline_stages SET stage_name = ?, color = ?, is_won = ?, is_lost = ? WHERE stage_id = ?")
        ->execute([$stage_name, $color, $is_won, $is_lost, $stage_id]);

    logActivity($pdo, $_SESSION['user_id'], "Updated pipeline stage: $stage_name");
    echo json_encode(['success' => true, 'message' => "Stage updated."]);
    exit;
}

// ── DELETE ─────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    if (!canDelete('crm_pipeline')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
    }

    $stage_id = intval($_POST['stage_id'] ?? 0);
    if (!$stage_id) { echo json_encode(['success' => false, 'message' => 'Stage ID required']); exit; }

    $stg = $pdo->prepare("SELECT stage_name, is_won, is_lost FROM crm_pipeline_stages WHERE stage_id = ? AND status = 'active'");
    $stg->execute([$stage_id]);
    $stg = $stg->fetch(PDO::FETCH_ASSOC);
    if (!$stg) { echo json_encode(['success' => false, 'message' => 'Stage not found']); exit; }

    if ($stg['is_won'] || $stg['is_lost']) {
        echo json_encode(['success' => false, 'message' => 'The Won and Lost stages cannot be deleted.']); exit;
    }

    $count = (int)$pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE pipeline_stage_id = ? AND status != 'deleted'")->execute([$stage_id]) && 0;
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM crm_leads WHERE pipeline_stage_id = ? AND status != 'deleted'");
    $countStmt->execute([$stage_id]);
    $count = (int)$countStmt->fetchColumn();
    if ($count > 0) {
        echo json_encode(['success' => false, 'message' => "Cannot delete: $count lead(s) are in this stage. Move them first."]); exit;
    }

    $pdo->prepare("UPDATE crm_pipeline_stages SET status = 'deleted' WHERE stage_id = ?")->execute([$stage_id]);

    logActivity($pdo, $_SESSION['user_id'], "Deleted pipeline stage: {$stg['stage_name']}");
    echo json_encode(['success' => true, 'message' => "Stage \"{$stg['stage_name']}\" deleted."]);
    exit;
}

// ── REORDER ────────────────────────────────────────────────────────────────────
if ($action === 'reorder') {
    if (!canEdit('crm_pipeline')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
    }

    $order = $_POST['order'] ?? [];
    if (!is_array($order) || empty($order)) {
        echo json_encode(['success' => false, 'message' => 'Order array required']); exit;
    }

    $upd = $pdo->prepare("UPDATE crm_pipeline_stages SET stage_order = ? WHERE stage_id = ?");
    foreach ($order as $pos => $sid) {
        $upd->execute([$pos + 1, intval($sid)]);
    }

    echo json_encode(['success' => true, 'message' => 'Stage order saved.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
