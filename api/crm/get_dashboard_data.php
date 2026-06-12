<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if (!canView('crm_dashboard')) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

require_once __DIR__ . '/../../core/project_scope.php';

$period = $_GET['period'] ?? 'this_month';
$now    = date('Y-m-d');
$year   = date('Y');

switch ($period) {
    case 'last_month':
        $from = date('Y-m-01', strtotime('first day of last month'));
        $to   = date('Y-m-t',  strtotime('last day of last month'));
        break;
    case 'this_year':
        $from = "$year-01-01";
        $to   = "$year-12-31";
        break;
    case 'all':
        $from = '2000-01-01';
        $to   = '2099-12-31';
        break;
    default: // this_month
        $from = date('Y-m-01');
        $to   = date('Y-m-t');
        break;
}

$scope = scopeFilterSqlNullable('project', 'cl');

try {
    // ── KPI stats ──────────────────────────────────────────────────────────
    $kpi = $pdo->prepare("
        SELECT
            COUNT(*)                                                          AS total_leads,
            SUM(cl.created_at BETWEEN ? AND ?)                               AS new_this_period,
            COALESCE(SUM(CASE WHEN ps.is_lost=0 AND cl.status='active' THEN cl.lead_value ELSE 0 END),0)
                                                                             AS pipeline_value,
            COALESCE(SUM(ps.is_won),0)                                       AS won_leads,
            COALESCE(SUM(ps.is_lost),0)                                      AS lost_leads,
            COALESCE(SUM(cl.converted),0)                                    AS converted_leads
        FROM crm_leads cl
        LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        WHERE cl.status != 'deleted' $scope
    ");
    $kpi->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $kpi_row = $kpi->fetch(PDO::FETCH_ASSOC);

    $total      = max(1, (int)$kpi_row['total_leads']);
    $won        = (int)$kpi_row['won_leads'];
    $conv_rate  = round($won / $total * 100, 1);

    // Activities due today
    $act_today = (int)$pdo->query("
        SELECT COUNT(*) FROM crm_lead_activities
        WHERE DATE(due_date) = CURDATE() AND status = 'pending'
    ")->fetchColumn();

    // Overdue activities
    $act_overdue = (int)$pdo->query("
        SELECT COUNT(*) FROM crm_lead_activities
        WHERE due_date < NOW() AND status = 'pending'
    ")->fetchColumn();

    // ── Chart 1 — Leads by Stage (doughnut) ──────────────────────────────
    $byStage = $pdo->prepare("
        SELECT ps.stage_name, ps.color, COUNT(cl.lead_id) AS cnt
        FROM crm_pipeline_stages ps
        LEFT JOIN crm_leads cl ON cl.pipeline_stage_id = ps.stage_id AND cl.status != 'deleted' $scope
        WHERE ps.status = 'active'
        GROUP BY ps.stage_id, ps.stage_name, ps.color
        ORDER BY ps.stage_order
    ");
    $byStage->execute();
    $stage_rows = $byStage->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 2 — Leads by Source (bar) ───────────────────────────────────
    $bySource = $pdo->prepare("
        SELECT lead_source, COUNT(*) AS cnt
        FROM crm_leads cl
        WHERE cl.status != 'deleted' $scope
        GROUP BY lead_source
        ORDER BY cnt DESC
    ");
    $bySource->execute();
    $source_rows = $bySource->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 3 — Monthly Pipeline (line, last 6 months) ─────────────────
    $monthly = $pdo->prepare("
        SELECT
            DATE_FORMAT(created_at, '%Y-%m') AS mo,
            COUNT(*) AS created
        FROM crm_leads cl
        WHERE cl.status != 'deleted'
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) $scope
        GROUP BY mo
        ORDER BY mo
    ");
    $monthly->execute();
    $monthly_rows = $monthly->fetchAll(PDO::FETCH_ASSOC);

    // ── Chart 4 — Win/Loss trend (grouped bar, last 6 months) ────────────
    $winloss = $pdo->prepare("
        SELECT
            DATE_FORMAT(cl.updated_at, '%Y-%m') AS mo,
            SUM(ps.is_won)  AS won,
            SUM(ps.is_lost) AS lost
        FROM crm_leads cl
        LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        WHERE cl.status != 'deleted'
          AND cl.updated_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          AND (ps.is_won = 1 OR ps.is_lost = 1) $scope
        GROUP BY mo
        ORDER BY mo
    ");
    $winloss->execute();
    $wl_rows = $winloss->fetchAll(PDO::FETCH_ASSOC);

    // ── Recent Leads (5) ──────────────────────────────────────────────────
    $recent = $pdo->prepare("
        SELECT cl.lead_id, cl.lead_code,
               TRIM(CONCAT_WS(' ', cl.first_name, cl.last_name)) AS full_name,
               cl.company_name, cl.lead_value,
               ps.stage_name, ps.color AS stage_color,
               cl.created_at
        FROM crm_leads cl
        LEFT JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        WHERE cl.status != 'deleted' $scope
        ORDER BY cl.created_at DESC
        LIMIT 5
    ");
    $recent->execute();
    $recent_rows = $recent->fetchAll(PDO::FETCH_ASSOC);

    // ── Due Activities (10) ───────────────────────────────────────────────
    $due = $pdo->query("
        SELECT a.activity_id, a.subject, a.activity_type, a.due_date,
               TRIM(CONCAT_WS(' ', cl.first_name, cl.last_name)) AS lead_name,
               cl.lead_code, cl.lead_id
        FROM crm_lead_activities a
        JOIN crm_leads cl ON a.lead_id = cl.lead_id
        WHERE a.due_date <= DATE_ADD(NOW(), INTERVAL 1 DAY)
          AND a.status = 'pending'
          AND cl.status != 'deleted'
        ORDER BY a.due_date ASC
        LIMIT 10
    ");
    $due_rows = $due->fetchAll(PDO::FETCH_ASSOC);

    // ── Top Assignees (5 by Won leads) ────────────────────────────────────
    $top = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)),''), u.username) AS name,
               COUNT(cl.lead_id) AS won_count,
               COALESCE(SUM(cl.lead_value), 0) AS won_value
        FROM crm_leads cl
        JOIN users u ON cl.assigned_to = u.user_id
        JOIN crm_pipeline_stages ps ON cl.pipeline_stage_id = ps.stage_id
        WHERE ps.is_won = 1 AND cl.status != 'deleted' $scope
        GROUP BY cl.assigned_to, u.first_name, u.last_name, u.username
        ORDER BY won_count DESC
        LIMIT 5
    ");
    $top->execute();
    $top_rows = $top->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'kpi' => [
            'total_leads'        => (int)$kpi_row['total_leads'],
            'new_this_period'    => (int)$kpi_row['new_this_period'],
            'pipeline_value'     => (float)$kpi_row['pipeline_value'],
            'conversion_rate'    => $conv_rate,
            'activities_today'   => $act_today,
            'overdue_activities' => $act_overdue,
            'won_leads'          => $won,
            'lost_leads'         => (int)$kpi_row['lost_leads'],
            'converted_leads'    => (int)$kpi_row['converted_leads'],
        ],
        'charts' => [
            'by_stage'  => $stage_rows,
            'by_source' => $source_rows,
            'monthly'   => $monthly_rows,
            'win_loss'  => $wl_rows,
        ],
        'tables' => [
            'recent_leads'   => $recent_rows,
            'due_activities' => $due_rows,
            'top_assignees'  => $top_rows,
        ],
    ]);

} catch (PDOException $e) {
    error_log('get_dashboard_data error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error.']);
}
