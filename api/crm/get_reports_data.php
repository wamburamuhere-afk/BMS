<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('crm_reports')) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$report    = trim($_GET['report'] ?? '');
$from      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-01-01');
$to        = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
$project_id = (isset($_GET['project_id']) && $_GET['project_id'] !== '') ? (int)$_GET['project_id'] : null;

if ($project_id !== null && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: project not in your scope.']);
    exit;
}

$scopeSql = '';
$scopeParams = [];
if ($project_id !== null) {
    $scopeSql    = ' AND cl.project_id = ?';
    $scopeParams = [$project_id];
} else {
    $scopeSql = scopeFilterSqlNullable('project', 'cl');
}

$baseWhere  = "cl.status != 'deleted' AND DATE(cl.created_at) BETWEEN ? AND ?" . $scopeSql;
$baseParams = array_merge([$from, $to], $scopeParams);

try {
    switch ($report) {

        // ── CONVERSION FUNNEL ─────────────────────────────────────────────
        case 'funnel':
            $stmt = $pdo->prepare("
                SELECT ps.stage_id, ps.stage_name, ps.color, ps.stage_order,
                       ps.is_won, ps.is_lost,
                       COUNT(cl.lead_id)                   AS lead_count,
                       COALESCE(SUM(cl.lead_value), 0)     AS total_value
                FROM crm_pipeline_stages ps
                LEFT JOIN crm_leads cl ON cl.pipeline_stage_id = ps.stage_id AND $baseWhere
                WHERE ps.status = 'active'
                GROUP BY ps.stage_id
                ORDER BY ps.stage_order ASC
            ");
            $stmt->execute($baseParams);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── AGENT PERFORMANCE ─────────────────────────────────────────────
        case 'agent':
            $stmt = $pdo->prepare("
                SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), u.username) AS agent_name,
                       COUNT(cl.lead_id)                                                       AS total_leads,
                       COALESCE(SUM(ps.is_won = 1), 0)                                        AS won_leads,
                       COALESCE(SUM(ps.is_lost = 1), 0)                                       AS lost_leads,
                       ROUND(COALESCE(SUM(ps.is_won = 1),0) / NULLIF(COUNT(cl.lead_id),0) * 100, 1) AS win_rate,
                       COALESCE(AVG(CASE WHEN ps.is_won = 1 THEN cl.lead_value END), 0)        AS avg_won_value,
                       COALESCE(SUM(CASE WHEN ps.is_won = 1 THEN cl.lead_value ELSE 0 END), 0) AS total_won_value,
                       COUNT(DISTINCT la.activity_id)                                          AS activities_logged
                FROM crm_leads cl
                LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
                LEFT JOIN users u ON cl.assigned_to = u.user_id
                LEFT JOIN crm_lead_activities la ON la.lead_id = cl.lead_id AND la.status != 'deleted'
                WHERE $baseWhere AND cl.assigned_to IS NOT NULL
                GROUP BY cl.assigned_to
                ORDER BY won_leads DESC, total_leads DESC
            ");
            $stmt->execute($baseParams);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── ACTIVITY REPORT ───────────────────────────────────────────────
        case 'activity':
            $stmt = $pdo->prepare("
                SELECT la.activity_type,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), u.username) AS agent_name,
                       COUNT(la.activity_id)  AS total,
                       COALESCE(SUM(la.status = 'done'), 0)    AS done,
                       COALESCE(SUM(la.status = 'pending'), 0) AS pending,
                       COALESCE(SUM(la.status = 'overdue'), 0) AS overdue
                FROM crm_lead_activities la
                JOIN crm_leads cl ON cl.lead_id = la.lead_id AND $baseWhere
                LEFT JOIN users u ON la.created_by = u.user_id
                WHERE la.status != 'deleted'
                GROUP BY la.activity_type, la.created_by
                ORDER BY total DESC
            ");
            $stmt->execute($baseParams);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── PIPELINE FORECAST ─────────────────────────────────────────────
        case 'forecast':
            $stmt = $pdo->prepare("
                SELECT ps.stage_name,
                       cl.lead_id, cl.lead_code, cl.first_name, cl.last_name, cl.company_name,
                       cl.lead_value, cl.probability, cl.expected_close_date,
                       ROUND(cl.lead_value * cl.probability / 100, 0) AS weighted_value,
                       COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), u.username) AS assigned_name
                FROM crm_leads cl
                LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
                LEFT JOIN users u ON cl.assigned_to = u.user_id
                WHERE cl.status != 'deleted'
                  AND cl.converted = 0
                  AND COALESCE(ps.is_won, 0) = 0
                  AND COALESCE(ps.is_lost, 0) = 0
                  AND cl.expected_close_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                  $scopeSql
                ORDER BY cl.expected_close_date ASC
            ");
            $stmt->execute($scopeParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totals = ['30d' => 0.0, '60d' => 0.0, '90d' => 0.0];
            foreach ($rows as $r) {
                $d = $r['expected_close_date'];
                $w = (float)$r['weighted_value'];
                if ($d <= date('Y-m-d', strtotime('+30 days'))) $totals['30d'] += $w;
                if ($d <= date('Y-m-d', strtotime('+60 days'))) $totals['60d'] += $w;
                $totals['90d'] += $w;
            }
            echo json_encode(['success' => true, 'data' => $rows, 'totals' => $totals]);
            break;

        // ── WIN/LOSS ANALYSIS ─────────────────────────────────────────────
        case 'winloss':
            $stmt = $pdo->prepare("
                SELECT cl.lost_reason,
                       COUNT(*) AS count,
                       COALESCE(SUM(cl.lead_value), 0) AS total_value,
                       ps.stage_name AS lost_at_stage
                FROM crm_leads cl
                LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
                WHERE $baseWhere AND ps.is_lost = 1
                GROUP BY cl.lost_reason, ps.stage_id
                ORDER BY count DESC
            ");
            $stmt->execute($baseParams);
            $lost = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt2 = $pdo->prepare("
                SELECT COUNT(*) AS won_count, COALESCE(SUM(cl.lead_value), 0) AS won_value
                FROM crm_leads cl
                LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
                WHERE $baseWhere AND ps.is_won = 1
            ");
            $stmt2->execute($baseParams);
            $won = $stmt2->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'lost' => $lost, 'won' => $won]);
            break;

        // ── CAMPAIGN ROI ──────────────────────────────────────────────────
        case 'campaign':
            $stmt = $pdo->prepare("
                SELECT mc.campaign_id, mc.campaign_name, mc.type, mc.budget, mc.spent,
                       COUNT(cl.lead_id)                                           AS leads_count,
                       COALESCE(SUM(cl.converted = 1), 0)                          AS converted_count,
                       COALESCE(SUM(CASE WHEN ps.is_won = 1 THEN cl.lead_value ELSE 0 END), 0) AS won_value,
                       ROUND(COALESCE(SUM(cl.converted = 1),0) / NULLIF(COUNT(cl.lead_id),0) * 100, 1) AS conversion_rate,
                       CASE WHEN COALESCE(mc.budget, 0) > 0
                            THEN ROUND(COALESCE(SUM(CASE WHEN ps.is_won=1 THEN cl.lead_value ELSE 0 END),0) / mc.budget * 100, 1)
                            ELSE NULL END AS roi_pct
                FROM marketing_campaigns mc
                LEFT JOIN crm_leads cl ON cl.campaign_id = mc.campaign_id AND cl.status != 'deleted'
                LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
                WHERE mc.is_deleted = 0
                GROUP BY mc.campaign_id
                ORDER BY won_value DESC
            ");
            $stmt->execute();
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── LEAD SOURCE REPORT ────────────────────────────────────────────
        case 'source':
            $stmt = $pdo->prepare("
                SELECT cl.lead_source,
                       COUNT(cl.lead_id)                                                              AS total,
                       COALESCE(SUM(ps.is_won = 1), 0)                                               AS won,
                       COALESCE(SUM(cl.converted = 1), 0)                                            AS converted,
                       COALESCE(AVG(cl.lead_value), 0)                                               AS avg_value,
                       COALESCE(SUM(CASE WHEN ps.is_won=1 THEN cl.lead_value ELSE 0 END), 0)          AS won_value,
                       ROUND(COALESCE(SUM(ps.is_won=1),0) / NULLIF(COUNT(cl.lead_id),0) * 100, 1)   AS win_rate
                FROM crm_leads cl
                LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
                WHERE $baseWhere
                GROUP BY cl.lead_source
                ORDER BY total DESC
            ");
            $stmt->execute($baseParams);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown report type']);
    }
} catch (PDOException $e) {
    error_log('get_reports_data error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
