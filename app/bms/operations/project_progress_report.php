<?php
// app/bms/operations/project_progress_report.php
require_once __DIR__ . '/../../../roots.php';

if (!isAuthenticated()) {
    header('Location: ' . getUrl('login'));
    exit;
}

// Ensure user info is in session for print footer
if (isset($_SESSION['user_id']) && (!isset($_SESSION['first_name']) || empty($_SESSION['first_name']) || !isset($_SESSION['username']))) {
    global $pdo;
    $stmtU = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE user_id = ?");
    $stmtU->execute([$_SESSION['user_id']]);
    $uData = $stmtU->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $_SESSION['first_name'] = $uData['first_name'] ?? '';
        $_SESSION['last_name'] = $uData['last_name'] ?? '';
        $_SESSION['username'] = $uData['username'] ?? '';
    }
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    die("Error: Project ID is required.");
}

global $pdo;

try {
    // 1. Fetch Project Details
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE project_id = ?");
    $stmt->execute([$id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        die("Error: Project not found.");
    }

    // 2. Fetch Financials for Progress Calculation
    // Invoices (Revenue)
    $stmt = $pdo->prepare("SELECT SUM(grand_total) FROM invoices WHERE project_id = ? AND status NOT IN ('cancelled', 'void', 'draft', 'pending')");
    $stmt->execute([$id]);
    $total_revenue = $stmt->fetchColumn() ?: 0;

    // Sales Orders (Total Expected)
    $stmt = $pdo->prepare("SELECT SUM(grand_total) FROM sales_orders WHERE project_id = ? AND status != 'cancelled' AND is_quote = 0");
    $stmt->execute([$id]);
    $total_orders = $stmt->fetchColumn() ?: 0;

    // Expenses (Vouchers + Expenses)
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM payment_vouchers WHERE project_id = ? AND status IN ('approved', 'paid')");
    $stmt->execute([$id]);
    $total_vouchers = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE project_id = ? AND status IN ('approved', 'paid')");
    $stmt->execute([$id]);
    $total_expenses = $stmt->fetchColumn() ?: 0;

    $total_actual_cost = $total_vouchers + $total_expenses;

    // Budget
    $stmt = $pdo->prepare("SELECT SUM(allocated_amount) FROM budgets WHERE project_id = ? AND status != 'rejected'");
    $stmt->execute([$id]);
    $allocated_budget = $stmt->fetchColumn() ?: 0;
    
    $master_budget = $project['budget'] ?: 0;
    $total_project_budget = $allocated_budget > 0 ? $allocated_budget : $master_budget;

    // 3. Calculation Logic (Matching get_project.php)
    
    // Financial Progress (40%)
    $financial_progress = 0;
    if ($total_orders > 0) {
        $financial_progress = min(100, round(($total_revenue / $total_orders) * 100, 2));
    } elseif ($total_revenue > 0) {
        $financial_progress = 100;
    }

    // Timeline Progress (30%)
    $timeline_progress = 0;
    $start_date = strtotime($project['start_date']);
    $deadline = $project['deadline'] ? strtotime($project['deadline']) : null;
    $today = time();
    $days_total = 0;
    $days_elapsed = 0;
    $days_remaining = 0;

    if ($deadline && $start_date < $deadline) {
        $days_total = round(($deadline - $start_date) / 86400);
        $days_elapsed = round(($today - $start_date) / 86400);
        $days_remaining = $days_total - $days_elapsed;
        $timeline_progress = min(100, max(0, round(($days_elapsed / $days_total) * 100, 2)));
    }

    // Budget Progress (30%)
    $budget_progress = 0;
    if ($total_project_budget > 0) {
        $budget_progress = min(100, round(($total_actual_cost / $total_project_budget) * 100, 2));
    }

    $calculated_progress = round(($financial_progress * 0.4) + ($timeline_progress * 0.3) + ($budget_progress * 0.3), 2);
    $display_progress = ($project['progress_percent'] > 0) ? $project['progress_percent'] : $calculated_progress;

    // Performance Status
    $status_label = "On Track";
    $status_color = "success";
    
    if ($deadline && $today > $deadline && $display_progress < 100) {
        $status_label = "OVERDUE";
        $status_color = "danger";
    } elseif ($timeline_progress > $display_progress + 15) {
        $status_label = "BEHIND SCHEDULE";
        $status_color = "warning";
    } elseif ($display_progress > $timeline_progress + 15) {
        $status_label = "AHEAD OF SCHEDULE";
        $status_color = "primary";
    }

    // Fetch company settings for print header
    $company_name = getSetting('company_name', 'BMS');
    $company_logo = getSetting('company_logo', '');
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

function getProgressColor($p) {
    if ($p < 30) return '#dc3545'; // red
    if ($p < 75) return '#ffc107'; // yellow
    return '#198754'; // green
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Progress Report - <?= htmlspecialchars($project['project_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f6; color: #333; }
        .report-page { max-width: 900px; margin: 30px auto; background: white; padding: 50px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .header-section { border-bottom: 3px solid #0d6efd; padding-bottom: 25px; margin-bottom: 35px; }
        .project-title { font-size: 28px; font-weight: 800; color: #212529; }
        .progress-circle-container { position: relative; width: 180px; height: 180px; margin: 0 auto; }
        .progress-circle-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
        .progress-circle-text h2 { font-size: 36px; font-weight: 800; margin: 0; }
        .metric-card { background: #f8f9fa; border-radius: 12px; padding: 20px; height: 100%; border-top: 4px solid #0d6efd; }
        .metric-title { font-size: 11px; font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
        .metric-value { font-size: 20px; font-weight: 800; }
        .section-title { font-size: 16px; font-weight: 700; color: #212529; margin-top: 40px; margin-bottom: 20px; display: flex; align-items: center; }
        .section-title i { margin-right: 10px; color: #0d6efd; }
        .progress-line-container { height: 10px; background: #e9ecef; border-radius: 5px; margin-bottom: 5px; overflow: hidden; }
        .status-banner { padding: 15px; border-radius: 10px; text-align: center; font-weight: 700; font-size: 18px; margin-bottom: 30px; letter-spacing: 1px; }
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .report-page { 
                box-shadow: none; 
                margin: 0; 
                max-width: 100%; 
                padding: 20px; 
                padding-bottom: 3.5cm !important; /* The PELE BUFFER for data isolation */
            }
            .d-print-none { display: none !important; }
            
            /* Fixed print footer on every page */
            .fixed-print-footer {
                position: fixed !important;
                bottom: 0 !important;
                left: 0;
                right: 0;
                text-align: center;
                background: white !important;
                padding: 10px 0;
                border-top: 1px solid #ddd !important;
                font-size: 10px;
                z-index: 999999 !important;
                -webkit-print-color-adjust: exact;
                height: 1.5cm;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }

            /* Flexible Tables for Portrait Fitting */
            table { 
                width: 100% !important; 
                table-layout: auto !important;
                word-wrap: break-word !important;
                font-size: 9pt !important;
            }
            th, td { 
                word-break: break-word !important;
                white-space: normal !important;
                padding: 4px 2px !important;
            }
            
            @page {
                size: auto;
                margin: 0.5in 0.5in 1in 0.5in !important;
            }
        }
    </style>
</head>
<body>

<div class="report-page">
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <button onclick="window.close()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle"></i> Close</button>
        <button onclick="window.print()" class="btn btn-primary px-4 shadow-sm"><i class="bi bi-printer me-2"></i> Print Report</button>
    </div>

    <div class="header-section text-center mb-5">
        <?php if(!empty($company_logo)): ?>
            <div class="mb-3">
                <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h1>
        <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT PROGRESS REPORT</h3>
        <div class="mx-auto bg-primary mb-3" style="width: 60px; height: 3px; border-radius: 2px;"></div>
        
        <div class="d-flex justify-content-center gap-4 mt-3">
            <div class="text-start">
                <div class="fw-bold text-dark"><?= htmlspecialchars($project['project_name'] ?? '') ?></div>
                <div class="small text-muted">Project ID: #<?= $project['project_id'] ?></div>
            </div>
            <div class="text-end border-start ps-4">
                <div class="small text-muted text-uppercase fw-bold">Generated</div>
                <div class="fw-bold"><?= date('M d, Y') ?></div>
            </div>
        </div>
    </div>

    <!-- Status Banner -->
    <div class="status-banner bg-<?= $status_color ?> text-white text-uppercase">
        CURRENT STATUS: <?= $status_label ?>
    </div>

    <div class="row align-items-center mb-5">
        <div class="col-md-5 text-center">
            <div class="progress-circle-container">
                <svg viewBox="0 0 36 36" class="circular-chart" style="width: 100%; height: 100%;">
                    <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="#eee" stroke-width="2.5"></path>
                    <path class="circle" stroke-dasharray="<?= $display_progress ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="<?= getProgressColor($display_progress) ?>" stroke-width="2.5" stroke-linecap="round"></path>
                </svg>
                <div class="progress-circle-text">
                    <h2 style="color: <?= getProgressColor($display_progress) ?>;"><?= round($display_progress) ?>%</h2>
                    <div class="small fw-bold text-muted text-uppercase">Complete</div>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="ps-md-4">
                <h6 class="fw-bold mb-3">Completion Metrics Analysis</h6>
                <p class="text-muted small mb-4">
                    The overall progress is calculated based on a weighted average of financial invoicing (40%), 
                    timeline elapsed (30%), and budget utilization (30%). 
                    <?= $project['progress_percent'] > 0 ? "<strong>Note:</strong> A manual progress override of " . $project['progress_percent'] . "% has been applied." : "" ?>
                </p>
                
                <div class="mb-3">
                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>Financial Completion</span>
                        <span><?= round($financial_progress) ?>%</span>
                    </div>
                    <div class="progress-line-container">
                        <div class="progress-bar bg-primary" style="width: <?= $financial_progress ?>%"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>Time Elapsed</span>
                        <span><?= round($timeline_progress) ?>%</span>
                    </div>
                    <div class="progress-line-container">
                        <div class="progress-bar bg-info" style="width: <?= $timeline_progress ?>%"></div>
                    </div>
                </div>

                <div class="mb-0">
                    <div class="d-flex justify-content-between small fw-bold mb-1">
                        <span>Budget Utilization</span>
                        <span><?= round($budget_progress) ?>%</span>
                    </div>
                    <div class="progress-line-container">
                        <div class="progress-bar bg-warning" style="width: <?= $budget_progress ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <div class="metric-card border-primary">
                <div class="metric-title">Project Schedule</div>
                <div class="metric-value"><?= $days_remaining > 0 ? $days_remaining . " Days Left" : ($days_remaining < 0 ? abs($days_remaining) . " Days Overdue" : "Due Today") ?></div>
                <div class="small text-muted mt-2">Deadline: <?= $project['deadline'] ? date('M d, Y', strtotime($project['deadline'])) : 'Open' ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="metric-card border-success">
                <div class="metric-title">Budget Efficiency</div>
                <?php 
                    $eff = ($display_progress > 0) ? round(($display_progress / ($budget_progress ?: 1)) * 100) : 0;
                ?>
                <div class="metric-value"><?= $eff ?>% Rating</div>
                <div class="small text-muted mt-2">Progress per Shilling spent</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="metric-card border-info">
                <div class="metric-title">Current Priority</div>
                <div class="metric-value text-uppercase"><?= $project['priority'] ?></div>
                <div class="small text-muted mt-2">Urgency assigned to project</div>
            </div>
        </div>
    </div>

    <div class="section-title"><i class="bi bi-card-text"></i> EXECUTIVE SUMMARY</div>
    <div class="p-4 bg-light rounded-3 mb-4">
        <h6 class="fw-bold small mb-2 text-uppercase">Description / Scope</h6>
        <p class="small text-muted mb-4"><?= nl2br(htmlspecialchars($project['description'] ?: 'No description provided.')) ?></p>
        
        <h6 class="fw-bold small mb-2 text-uppercase">Progress Assessment</h6>
        <p class="small text-dark mb-0">
            As of <?= date('d/m/Y') ?>, the project is <strong><?= $status_label ?></strong>. 
            The team has achieved a <strong><?= round($display_progress) ?>%</strong> completion rate. 
            <?php if ($status_label == 'BEHIND SCHEDULE'): ?>
                Urgent attention is needed to address the gap between elapsed time (<?= round($timeline_progress) ?>%) and delivery progress (<?= round($display_progress) ?>%).
            <?php elseif ($status_label == 'OVERDUE'): ?>
                The project has passed its deadline of <?= date('d/m/Y', strtotime($project['deadline'])) ?> and requires immediate finalization.
            <?php else: ?>
                The project's burn rate and delivery speed are within acceptable parameters for the set deadline.
            <?php endif; ?>
        </p>
    </div>

    <div class="mt-5 pt-4 border-top">
        <div class="row g-3">
            <div class="col-6">
                <div class="border-bottom pb-4" style="width: 200px;"></div>
                <div class="small fw-bold mt-2">PROJECT MANAGER</div>
                <div class="small text-muted"><?= htmlspecialchars($project['project_manager'] ?: 'Not Assigned') ?></div>
            </div>
            <div class="col-6 text-end">
                <div class="border-bottom pb-4 ms-auto" style="width: 200px;"></div>
                <div class="small fw-bold mt-2">VERIFIED BY</div>
                <div class="small text-muted">Operations Department</div>
            </div>
        </div>
        
        <div class="fixed-print-footer d-none d-print-block">
            This report was <strong>Printed</strong> by <span class="text-dark fw-bold"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= $_SESSION['username'] ?? 'Staff' ?></span> 
            on <span class="text-dark fw-bold"><?= date('d M, Y \a\t H:i:s') ?></span>
            <div class="mt-1 fw-bold text-primary">Powered By BJP Technologies</div>
        </div>
    </div>

</div>

</body>
</html>
