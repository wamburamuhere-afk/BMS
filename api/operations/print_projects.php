<?php
// api/operations/print_projects.php
require_once __DIR__ . '/../../roots.php';

global $pdo;

$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

try {
    $where = ["1=1"];
    $params = [];
    if ($status) { $where[] = "p.status = ?"; $params[] = $status; }
    if ($search) { $where[] = "(p.project_name LIKE ? OR p.project_manager LIKE ?)"; $s = "%$search%"; $params[] = $s; $params[] = $s; }

    $where_clause = implode(" AND ", $where);

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            (SELECT COALESCE(SUM(grand_total), 0) FROM invoices WHERE project_id = p.project_id AND status NOT IN ('cancelled', 'void', 'draft', 'pending')) as total_revenue,
            (
                (SELECT COALESCE(SUM(amount), 0) FROM payment_vouchers WHERE project_id = p.project_id AND status IN ('approved', 'paid')) +
                (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE project_id = p.project_id AND status IN ('approved', 'paid'))
            ) as total_expense,
            (
                SELECT COALESCE(NULLIF(SUM(allocated_amount), 0), p.budget)
                FROM budgets
                WHERE project_id = p.project_id AND status = 'approved'
            ) as budget_val,
            (
                /* Aggregated Performance compatible with older MySQL version */
                SELECT ROUND(COALESCE(SUM(
                    CASE 
                        WHEN m.scope > 0 THEN 
                            LEAST(m.weight_percent, 
                                COALESCE((
                                    SELECT rd.actual_value 
                                    FROM project_progress_report_details rd
                                    JOIN project_progress_reports rh ON rd.report_id = rh.id
                                    WHERE rd.milestone_id = m.id 
                                    ORDER BY rh.report_date DESC, rd.id DESC 
                                    LIMIT 1
                                ), 0) / m.scope * m.weight_percent
                            )
                        ELSE 0 
                    END
                ), 0), 2)
                FROM project_milestones m
                WHERE m.project_id = p.project_id
                  AND m.parent_id IS NULL
                  AND m.scope_type = 'milestone'
            ) as performance_total
        FROM projects p
        WHERE $where_clause
        ORDER BY p.start_date DESC
    ");
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate profit and fix budget/progress
    foreach ($projects as &$p) {
        $p['budget_val']    = $p['budget_val'] ?: ($p['budget'] ?: 0);
        $p['total_revenue'] = (float)$p['total_revenue'];
        $p['total_expense'] = (float)$p['total_expense'];
        $p['profit']        = $p['total_revenue'] - $p['total_expense'];
        if ($p['performance_total'] !== null && $p['performance_total'] >= 0) {
            $p['progress_percent'] = (float)$p['performance_total'];
        }
    }
    unset($p);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }

// Fetch company settings
$c_name = getSetting('company_name', 'BMS');
$c_logo = getSetting('company_logo', '');

// Calculate summary stats from the fetched projects
$stat_active   = 0;
$stat_hold     = 0;
$stat_budget   = 0;
$stat_progress = 0;
foreach ($projects as $p) {
    if ($p['status'] === 'active') $stat_active++;
    if (in_array($p['status'], ['planning', 'on_hold'])) $stat_hold++;
    $stat_budget += (float)($p['budget'] ?? 0);
    $stat_progress += (float)($p['progress_percent'] ?? 0);
}
$total_projects  = count($projects);
$stat_avg_progress = $total_projects > 0 ? round($stat_progress / $total_projects, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Projects Report - <?= date('Y-m-d') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> 
        @media print { .no-print { display: none; } } 
        body { font-family: Arial, sans-serif; }
        .report-header { border-bottom: 3px solid #0d6efd; margin-bottom: 20px; padding-bottom: 15px; }
        table { font-size: 12px; }
        thead th { background-color: #f8f9fa !important; }
    </style> 
</head>
<body onload="window.print()">
    <div class="container-fluid mt-4">
        <!-- Professional Print Header -->
        <div class="print-header text-center mb-4">
            <?php if(!empty($c_logo)): ?>
                <div class="mb-3 text-center">
                    <img src="<?= htmlspecialchars('../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                </div>
            <?php endif; ?>
            <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
            
            <div class="mt-3 text-center">
                <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">BUSINESS PROJECTS REPORT</h2>
                
                <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
            </div>
            <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
        </div>

        <!-- Print Summary Cards -->
        <div class="mb-4">
            <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
                <div style="flex: 1; border: 1px solid #dee2e6; padding: 12px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 70px;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Active Projects</p>
                    <h3 style="color: #000; font-weight: 800; margin: 0; font-size: 16pt;"><?= $stat_active ?></h3>
                </div>
                <div style="flex: 1; border: 1px solid #dee2e6; padding: 12px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 70px;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Planning / Hold</p>
                    <h3 style="color: #000; font-weight: 800; margin: 0; font-size: 16pt;"><?= $stat_hold ?></h3>
                </div>
                <div style="flex: 1; border: 1px solid #dee2e6; padding: 12px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 70px;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Total Budget</p>
                    <h3 style="color: #000; font-weight: 800; margin: 0; font-size: 14pt;"><?= number_format($stat_budget, 2) ?> <small style="font-size: 0.6em;">TZS</small></h3>
                </div>
                <div style="flex: 1; border: 1px solid #dee2e6; padding: 12px; text-align: center; display: flex; flex-direction: column; justify-content: center; min-height: 70px;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 600;">Avg. Progress</p>
                    <h3 style="color: #000; font-weight: 800; margin: 0; font-size: 16pt;"><?= $stat_avg_progress ?>%</h3>
                </div>
            </div>
        </div>

        <table class="table table-bordered align-middle" style="color: #000 !important; font-size: 9.5pt; border: 1px solid #000 !important;">
            <thead class="table-light">
                <tr style="border-bottom: 2px solid #000 !important;">
                    <th style="width: 40px; text-align: center; border: 1px solid #000 !important;">S/NO</th>
                    <th style="border: 1px solid #000 !important;" class="text-uppercase small">Project Name</th>
                    <th style="border: 1px solid #000 !important;" class="text-uppercase small">Manager</th>
                    <th style="border: 1px solid #000 !important;" class="text-uppercase small">Timeline</th>
                    <th style="border: 1px solid #000 !important; text-align: right;" class="text-uppercase small">Budget (TZS)</th>
                    <th style="border: 1px solid #000 !important; text-align: center;" class="text-uppercase small">Progress</th>
                    <th style="border: 1px solid #000 !important;" class="text-uppercase small">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_budget = 0; 
                $sno = 1;
                foreach ($projects as $p): 
                    $total_budget += (float)$p['budget']; 
                ?>
                <tr>
                    <td style="text-align: center; border: 1px solid #000 !important;"><?= $sno++ ?></td>
                    <td style="border: 1px solid #000 !important;"><strong><?= htmlspecialchars($p['project_name']) ?></strong></td>
                    <td style="border: 1px solid #000 !important;"><?= htmlspecialchars($p['project_manager'] ?: 'N/A') ?></td>
                    <td style="border: 1px solid #000 !important; font-size: 0.85em;">
                        <span class="text-muted">Start:</span> <?= $p['start_date'] ?><br>
                        <span class="text-muted">End:</span> <?= $p['deadline'] ?: 'Ongoing' ?>
                    </td>
                    <td style="border: 1px solid #000 !important; text-align: right; font-weight: bold;"><?= number_format($p['budget'], 2) ?></td>
                    <td style="border: 1px solid #000 !important; text-align: center; font-weight: bold;">
                        <?= (float)$p['progress_percent'] ?>%
                    </td>
                    <td style="border: 1px solid #000 !important; text-align: center;"><span class="small text-uppercase fw-bold"><?= str_replace('_', ' ', $p['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light">
                <tr class="fw-bold">
                    <td colspan="4" style="text-align: right; border: 1px solid #000 !important;">AGGREGATE TOTAL BUDGET:</td>
                    <td style="text-align: right; border: 1px solid #000 !important; font-size: 1.1em;"><?= number_format($total_budget, 2) ?> TZS</td>
                    <td colspan="2" style="border: 1px solid #000 !important;"></td>
                </tr>
            </tfoot>
        </table>
        
        <div class="mt-4 no-print text-center">
            <button class="btn btn-primary shadow-sm px-4" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print Again
            </button>
            <button class="btn btn-outline-secondary px-4 ms-2" onclick="window.history.back()">
                <i class="bi bi-arrow-left me-1"></i> Back to Projects
            </button>
        </div>
    </div>
</body>
</html>
