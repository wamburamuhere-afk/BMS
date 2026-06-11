<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!canView('crm_pipeline')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
}

try {
    $scope = scopeFilterSqlNullable('project', 'cl');

    // All active stages in order
    $stagesStmt = $pdo->query("
        SELECT stage_id, stage_name, stage_order, color, is_won, is_lost
        FROM crm_pipeline_stages
        WHERE status = 'active'
        ORDER BY stage_order ASC
    ");
    $stages = $stagesStmt->fetchAll(PDO::FETCH_ASSOC);

    // All active (non-deleted, non-converted) leads with stage + assigned user
    $leadsStmt = $pdo->prepare("
        SELECT cl.lead_id, cl.lead_code, cl.first_name, cl.last_name,
               cl.company_name, cl.lead_value, cl.probability,
               cl.expected_close_date, cl.pipeline_stage_id, cl.converted,
               DATEDIFF(NOW(), cl.updated_at) AS days_in_stage,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS assigned_user_name
        FROM crm_leads cl
        LEFT JOIN users u ON cl.assigned_to = u.user_id
        WHERE cl.status != 'deleted'
        $scope
        ORDER BY cl.created_at DESC
    ");
    $leadsStmt->execute();
    $allLeads = $leadsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group leads by stage_id
    $leadsByStage = [];
    foreach ($allLeads as $lead) {
        $sid = (int)$lead['pipeline_stage_id'];
        $leadsByStage[$sid][] = $lead;
    }

    // Attach leads array + summary stats to each stage
    $result = [];
    foreach ($stages as $stage) {
        $sid   = (int)$stage['stage_id'];
        $leads = $leadsByStage[$sid] ?? [];
        $total_value = array_sum(array_column($leads, 'lead_value'));
        $result[] = [
            'stage_id'    => $sid,
            'stage_name'  => $stage['stage_name'],
            'stage_order' => (int)$stage['stage_order'],
            'color'       => $stage['color'],
            'is_won'      => (bool)$stage['is_won'],
            'is_lost'     => (bool)$stage['is_lost'],
            'count'       => count($leads),
            'total_value' => (float)$total_value,
            'leads'       => $leads,
        ];
    }

    echo json_encode(['success' => true, 'data' => $result]);

} catch (PDOException $e) {
    error_log('get_pipeline_data error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
