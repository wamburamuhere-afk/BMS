<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!canView('campaign_management')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
}

try {
    $where  = ['mc.is_deleted = 0'];
    $params = [];

    if (!empty($_GET['campaign_id'])) {
        $where[] = 'mc.campaign_id = ?';
        $params[] = intval($_GET['campaign_id']);
    }
    if (!empty($_GET['type'])) {
        $where[] = 'mc.type = ?';
        $params[] = trim($_GET['type']);
    }
    if (!empty($_GET['status'])) {
        $where[] = 'mc.status = ?';
        $params[] = trim($_GET['status']);
    }

    $where_sql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT mc.campaign_id, mc.campaign_name, mc.type, mc.target_audience,
               mc.start_date, mc.end_date, mc.budget, mc.spent, mc.status,
               COUNT(cl.lead_id)                                                    AS leads_count,
               COALESCE(SUM(cl.converted = 1), 0)                                  AS leads_converted,
               COALESCE(SUM(CASE WHEN cl.converted = 0 THEN cl.lead_value ELSE 0 END), 0) AS pipeline_value
        FROM marketing_campaigns mc
        LEFT JOIN crm_leads cl ON cl.campaign_id = mc.campaign_id AND cl.status != 'deleted'
        WHERE $where_sql
        GROUP BY mc.campaign_id
        ORDER BY mc.start_date DESC, mc.campaign_id DESC
    ");
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total  = count($campaigns);
    $active = 0;
    $budget = 0.0;
    $spent  = 0.0;
    foreach ($campaigns as $c) {
        if ($c['status'] === 'Active') $active++;
        $budget += (float)$c['budget'];
        $spent  += (float)$c['spent'];
    }

    echo json_encode([
        'success' => true,
        'data'    => $campaigns,
        'stats'   => compact('total', 'active', 'budget', 'spent'),
    ]);

} catch (PDOException $e) {
    error_log('get_campaigns error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
