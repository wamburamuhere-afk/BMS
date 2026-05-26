<?php
require_once __DIR__ . '/../../roots.php';
global $pdo;

header('Content-Type: application/json');

try {
    if (!isAuthenticated()) throw new Exception('Unauthorized');
    
    $project_id = $_GET['project_id'] ?? null;
    $sc_id      = isset($_GET['sc_id']) && $_GET['sc_id'] !== '' ? intval($_GET['sc_id']) : null;
    $type = $_GET['type'] ?? 'daily'; // daily, weekly, monthly, quarterly, annual
    $date = $_GET['date'] ?? null;

    if (!$project_id) throw new Exception('Project ID is required');
    assertScopeForRecord('projects', 'project_id', intval($project_id));

    if ($type === 'daily') {
        $query = "SELECT pr.*,
                    CONCAT(COALESCE(u.user_role, u.role), ', ', u.first_name, ' ', u.last_name) as reported_by_name,
                    (SELECT SUM(progress_percent) FROM project_progress_report_details WHERE report_id = pr.id) as total_progress
                  FROM project_progress_reports pr
                  LEFT JOIN users u ON pr.created_by = u.user_id
                  WHERE pr.project_id = ? AND pr.report_type = 'daily' ";
        $params = [$project_id];
        // In SC mode: show only reports tagged to this SC; in main mode: show all
        if ($sc_id !== null) {
            $query .= " AND pr.sc_id = ? ";
            $params[] = $sc_id;
        }

        if ($date) {
            $query .= " AND pr.report_date = ? ";
            $params[] = $date;
        }

        $query .= " ORDER BY pr.report_date DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($date && count($reports) > 0) {
            $report_id = $reports[0]['id'];
            $stmtDetails = $pdo->prepare("
                SELECT d.*, m.description, m.unit, m.scope, m.weight_percent 
                FROM project_progress_report_details d
                JOIN project_milestones m ON d.milestone_id = m.id
                WHERE d.report_id = ?
            ");
            $stmtDetails->execute([$report_id]);
            $reports[0]['details'] = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

            // Fetch attachments for this report
            $stmtAtt = $pdo->prepare("SELECT id, attachment_name, file_path FROM project_progress_report_attachments WHERE report_id = ? ORDER BY id ASC");
            $stmtAtt->execute([$report_id]);
            $reports[0]['attachments'] = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

            // INTEL: Also fetch prev_details for daily view to allow cumulative calculations
            $stmtPrev = $pdo->prepare("
                SELECT d.milestone_id, SUM(d.actual_value) as prev_actual_value
                FROM project_progress_reports pr
                JOIN project_progress_report_details d ON pr.id = d.report_id
                WHERE pr.project_id = ? AND pr.report_type = 'daily' AND pr.report_date < ?
                GROUP BY d.milestone_id
            ");
            $stmtPrev->execute([$project_id, $date]);
            $reports[0]['prev_details'] = $stmtPrev->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    } else {
        // Aggregated reports: Weekly, Monthly, Quarterly, Annual
        $periodStart = $date; $periodEnd = $date;
        if ($type === 'weekly') {
            $periodStart = date('Y-m-d', strtotime('monday this week', strtotime($date)));
            $periodEnd = date('Y-m-d', strtotime('sunday this week', strtotime($date)));
        } elseif ($type === 'monthly') {
            $periodStart = date('Y-m-01', strtotime($date));
            $periodEnd = date('Y-m-t', strtotime($date));
        } elseif ($type === 'quarterly') {
            $month = date('n', strtotime($date));
            $quarter = ceil($month / 3);
            $periodStart = date('Y-m-d', mktime(0, 0, 0, ($quarter - 1) * 3 + 1, 1, date('Y', strtotime($date))));
            $periodEnd = date('Y-m-d', mktime(0, 0, 0, $quarter * 3, date('t', mktime(0, 0, 0, $quarter * 3, 1, date('Y', strtotime($date)))), date('Y', strtotime($date))));
        } elseif ($type === 'annual') {
            $periodStart = date('Y-01-01', strtotime($date));
            $periodEnd = date('Y-12-31', strtotime($date));
        }

        $stmtAgg = $pdo->prepare("
            SELECT d.milestone_id, SUM(d.actual_value) as actual_value, SUM(d.progress_percent) as progress_percent
            FROM project_progress_reports pr
            JOIN project_progress_report_details d ON pr.id = d.report_id
            WHERE pr.project_id = ? AND pr.report_type = 'daily' AND pr.report_date BETWEEN ? AND ?
            GROUP BY d.milestone_id
        ");
        $stmtAgg->execute([$project_id, $periodStart, $periodEnd]);
        $details = $stmtAgg->fetchAll(PDO::FETCH_ASSOC);

        $stmtPrev = $pdo->prepare("
            SELECT d.milestone_id, SUM(d.actual_value) as prev_actual_value
            FROM project_progress_reports pr
            JOIN project_progress_report_details d ON pr.id = d.report_id
            WHERE pr.project_id = ? AND pr.report_type = 'daily' AND pr.report_date < ?
            GROUP BY d.milestone_id
        ");
        $stmtPrev->execute([$project_id, $periodStart]);
        $prev_map = $stmtPrev->fetchAll(PDO::FETCH_KEY_PAIR);

        $reports = [[
            'id' => 0,
            'report_date' => $date,
            'report_type' => $type,
            'details' => $details,
            'prev_details' => $prev_map,
            'total_progress' => array_sum(array_column($details, 'progress_percent'))
        ]];
    }

    // INTEL: Calculate CUMULATIVE Progress up to the point of $date / periodEnd
    $targetDate = $date ?: date('Y-m-d');
    $calcUpTo = ($type === 'daily') ? $targetDate : $periodEnd;

    $stmtCumRaw = $pdo->prepare("
        SELECT d.milestone_id, SUM(d.actual_value) as cum_act
        FROM project_progress_reports pr
        JOIN project_progress_report_details d ON pr.id = d.report_id
        WHERE pr.project_id = ? AND pr.report_type = 'daily' 
        GROUP BY d.milestone_id
    ");
    $stmtCumRaw->execute([$project_id]);
    $cum_act_map = $stmtCumRaw->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmtM = $pdo->prepare("SELECT id, parent_id, weight_percent, scope FROM project_milestones WHERE project_id = ? AND scope_type = 'milestone'");
    $stmtM->execute([$project_id]);
    $m_list = $stmtM->fetchAll(PDO::FETCH_ASSOC);
    
    $m_tree = [];
    foreach($m_list as $m) {
        $m['children'] = [];
        $m['act'] = (float)($cum_act_map[$m['id']] ?? 0);
        $m_tree[$m['id']] = $m;
    }
    
    $roots = [];
    foreach($m_tree as &$m) {
        if ($m['parent_id'] && isset($m_tree[$m['parent_id']])) {
            $m_tree[$m['parent_id']]['children'][] = &$m;
        } else {
            $roots[] = &$m;
        }
    }
    unset($m);

    function recurseCalc(&$m, $rootWeight) {
        if (count($m['children']) > 0) {
            $sumP = 0;
            foreach($m['children'] as &$c) { $sumP += recurseCalc($c, $rootWeight); }
            $m['p'] = $sumP / count($m['children']);
        } else {
            $scope = (float)$m['scope'];
            $m['p'] = ($scope > 0) ? ($m['act'] / $scope) * $rootWeight : 0;
        }
        return $m['p'];
    }

    $cumulative_total = 0;
    foreach($roots as &$r) {
        $rWeight = (float)$r['weight_percent'];
        $cumulative_total += recurseCalc($r, $rWeight);
    }
    $cumulative_total = round(min(100, $cumulative_total), 2);

    $stmtOverall = $pdo->prepare("SELECT progress_percent FROM projects WHERE project_id = ?");
    $stmtOverall->execute([$project_id]);
    $overall_p = (float)$stmtOverall->fetchColumn() ?: 0;

    echo json_encode([
        'success' => true, 
        'data' => $reports,
        'overall_progress' => $overall_p,
        'cumulative_total' => $cumulative_total,
        'cumulative_map' => $cum_act_map
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
