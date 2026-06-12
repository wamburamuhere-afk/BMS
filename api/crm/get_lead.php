<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canView('crm_leads')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    $scope = scopeFilterSqlNullable('project', 'cl');
    $stmt = $pdo->prepare("
        SELECT cl.*,
               ps.stage_name, ps.color AS stage_color, ps.is_won, ps.is_lost,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', ua.first_name, ua.last_name)), ''), ua.username) AS assigned_name,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', uc.first_name, uc.last_name)), ''), uc.username) AS created_by_name
        FROM crm_leads cl
        LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        LEFT JOIN users ua ON cl.assigned_to = ua.user_id
        LEFT JOIN users uc ON cl.created_by  = uc.user_id
        WHERE cl.lead_id = ? AND cl.status != 'deleted' $scope
    ");
    $stmt->execute([$id]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lead) {
        echo json_encode(['success' => false, 'message' => 'Lead not found']);
        exit;
    }

    // Labels attached to this lead
    $lblStmt = $pdo->prepare("
        SELECT l.label_id, l.label_name, l.color
        FROM crm_lead_labels ll
        JOIN crm_labels l ON ll.label_id = l.label_id AND l.status = 'active'
        WHERE ll.lead_id = ?
    ");
    $lblStmt->execute([$id]);
    $lead['labels'] = $lblStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $lead]);

} catch (PDOException $e) {
    error_log("get_lead error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
