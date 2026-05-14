<?php
// app/bms/operations/project_budget_report.php
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

    // 2. Fetch Budget Items grouped by category
    $stmt = $pdo->prepare("
        SELECT 
            b.category_id,
            ec.name AS category_name,
            SUM(b.allocated_amount) as total_allocated
        FROM budgets b
        LEFT JOIN expense_categories ec ON b.category_id = ec.id
        WHERE b.project_id = ? AND b.status != 'rejected'
        GROUP BY b.category_id, ec.name
    ");
    $stmt->execute([$id]);
    $budget_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Actual Expenses grouped by category
    $stmt = $pdo->prepare("
        SELECT 
            e.category_id,
            SUM(e.amount) as total_actual
        FROM expenses e
        WHERE e.project_id = ? AND e.status IN ('approved', 'paid')
        GROUP BY e.category_id
    ");
    $stmt->execute([$id]);
    $expense_actuals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 4. Fetch Payment Vouchers grouped by category (name matching or direct)
    // Since vouchers use account_categories, we'll try to match by name or just sum them separately if not linked
    $stmt = $pdo->prepare("
        SELECT 
            ac.category_name,
            SUM(pv.amount) as total_voucher
        FROM payment_vouchers pv
        LEFT JOIN account_categories ac ON pv.expense_category_id = ac.category_id
        WHERE pv.project_id = ? AND pv.status IN ('approved', 'paid')
        GROUP BY ac.category_name
    ");
    $stmt->execute([$id]);
    $voucher_actuals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Aggregate Data
    $analysis = [];
    $grand_allocated = 0;
    $grand_actual = 0;

    foreach ($budget_items as $item) {
        $cat_id = $item['category_id'];
        $name = $item['category_name'];
        $allocated = (float)$item['total_allocated'];
        
        $actual_exp = isset($expense_actuals[$cat_id]) ? (float)$expense_actuals[$cat_id] : 0;
        // Check if vouchers match this category name
        $actual_vouch = isset($voucher_actuals[$name]) ? (float)$voucher_actuals[$name] : 0;
        
        $total_actual = $actual_exp + $actual_vouch;
        $variance = $allocated - $total_actual;
        $utilization = $allocated > 0 ? ($total_actual / $allocated) * 100 : 0;

        $analysis[] = [
            'category' => $name,
            'allocated' => $allocated,
            'actual' => $total_actual,
            'variance' => $variance,
            'utilization' => $utilization
        ];

        $grand_allocated += $allocated;
        $grand_actual += $total_actual;
    }

    // Sort by utilization desc to show "Red Flags" first
    usort($analysis, function($a, $b) {
        return $b['utilization'] <=> $a['utilization'];
    });

    // Fetch company settings for print header
    $company_name = getSetting('company_name', 'BMS');
    $company_logo = getSetting('company_logo', '');
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Budget Analysis - <?= htmlspecialchars($project['project_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f0f2f5; color: #1a1a1a; }
        .report-card { max-width: 1000px; margin: 40px auto; background: white; padding: 45px; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); border: 1px solid #e0e0e0; }
        .header-top { border-bottom: 2px solid #2d3436; padding-bottom: 25px; margin-bottom: 35px; }
        .report-main-title { font-size: 26px; font-weight: 800; color: #2d3436; text-transform: uppercase; letter-spacing: 1px; }
        .summary-box { background: #f8f9fa; border-radius: 15px; padding: 25px; margin-bottom: 40px; border-left: 6px solid #0984e3; }
        .variance-positive { color: #27ae60; font-weight: 700; }
        .variance-negative { color: #d63031; font-weight: 700; }
        .utilization-bar { height: 8px; border-radius: 4px; background: #dfe6e9; overflow: hidden; margin-top: 8px; }
        .utilization-fill { height: 100%; border-radius: 4px; transition: width 0.3s ease; }
        .status-pill { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        .table thead th { background: #f1f2f6; color: #2d3436; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border: none; padding: 15px; }
        .table tbody td { padding: 15px; vertical-align: middle; border-bottom: 1px solid #f1f2f6; }
        @media print {
            body { background: white; }
            .report-card { box-shadow: none; border: none; margin: 0; max-width: 100%; padding: 20px; }
            .d-print-none { display: none !important; }

            /* Fixed print footer on every page */
            .fixed-print-footer {
                position: fixed;
                bottom: 0px;
                left: 0;
                right: 0;
                text-align: center;
                background: white;
                padding: 10px 0;
                border-top: 1px solid #ddd;
                font-size: 10px;
                z-index: 1000;
            }
            .report-card { padding-bottom: 80px !important; }
        }
    </style>
</head>
<body>

<div class="report-card">
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <button onclick="window.close()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</button>
        <button onclick="window.print()" class="btn btn-dark px-4 shadow-sm"><i class="bi bi-printer me-2"></i> Print Analysis</button>
    </div>

    <div class="header-top text-center mb-5">
        <?php if(!empty($company_logo)): ?>
            <div class="mb-3">
                <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h1>
        <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">Budget Variance Analysis</h3>
        <div class="mx-auto bg-primary mb-3" style="width: 60px; height: 3px; border-radius: 2px;"></div>
        
        <div class="d-flex justify-content-center gap-4 mt-3">
            <div class="text-start">
                <div class="fw-bold text-dark"><?= htmlspecialchars($project['project_name'] ?? '') ?></div>
                <div class="small text-muted">Project ID: #<?= $project['project_id'] ?></div>
            </div>
            <div class="text-end border-start ps-4">
                <div class="small text-muted text-uppercase fw-bold">Analysis Date</div>
                <div class="fw-bold"><?= date('M d, Y') ?></div>
            </div>
        </div>
    </div>

    <div class="summary-box">
        <div class="row g-4 text-center">
            <div class="col-3 border-end">
                <div class="small text-muted text-uppercase fw-bold mb-1">Total Budget</div>
                <div class="h4 fw-bold mb-0 text-dark"><?= format_currency($grand_allocated) ?></div>
            </div>
            <div class="col-3 border-end">
                <div class="small text-muted text-uppercase fw-bold mb-1">Actual Spent</div>
                <div class="h4 fw-bold mb-0 text-dark"><?= format_currency($grand_actual) ?></div>
            </div>
            <div class="col-3 border-end">
                <div class="small text-muted text-uppercase fw-bold mb-1">Overall Variance</div>
                <?php $total_variance = $grand_allocated - $grand_actual; ?>
                <div class="h4 fw-bold mb-0 text-dark">
                    <?= $total_variance >= 0 ? '+' : '' ?><?= format_currency($total_variance) ?>
                </div>
            </div>
            <div class="col-3">
                <div class="small text-muted text-uppercase fw-bold mb-1">Utilization</div>
                <?php $total_util = $grand_allocated > 0 ? ($grand_actual / $grand_allocated) * 100 : 0; ?>
                <div class="h4 fw-bold mb-0 text-dark">
                    <?= round($total_util, 1) ?>%
                </div>
            </div>
        </div>
    </div>

    <h5 class="fw-bold mb-4 mt-5"><i class="bi bi-list-check me-2 text-primary"></i>Category-wise Performance</h5>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 50px;">S/NO</th>
                    <th>Expense Category</th>
                    <th class="text-end">Allocated</th>
                    <th class="text-end">Actual Spent</th>
                    <th class="text-end">Variance</th>
                    <th class="text-end" style="width: 200px;">Utilization Level</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($analysis as $row): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($row['category'] ?: 'Uncategorized') ?></td>
                        <td class="text-end"><?= format_currency($row['allocated']) ?></td>
                        <td class="text-end"><?= format_currency($row['actual']) ?></td>
                        <td class="text-end <?= $row['variance'] >= 0 ? 'variance-positive' : 'variance-negative' ?>">
                            <?= $row['variance'] >= 0 ? '+' : '' ?><?= format_currency($row['variance']) ?>
                        </td>
                        <td class="text-end">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge status-pill bg-<?= $row['utilization'] > 100 ? 'danger' : ($row['utilization'] > 85 ? 'warning' : 'light text-dark border') ?>">
                                    <?= round($row['utilization']) ?>%
                                </span>
                            </div>
                            <div class="utilization-bar">
                                <div class="utilization-fill bg-<?= $row['utilization'] > 100 ? 'danger' : ($row['utilization'] > 85 ? 'warning' : 'primary') ?>"
                                     style="width: <?= min(100, $row['utilization']) ?>%"></div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($analysis)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No budget items found for analysis.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="row mt-5">
        <div class="col-md-6">
            <div class="p-4 rounded border-start border-4 border-warning bg-light">
                <h6 class="fw-bold text-uppercase small mb-3"><i class="bi bi-info-circle me-2"></i>Summary Findings</h6>
                <p class="small text-muted mb-0">
                    <?php if ($total_util > 100): ?>
                        <span class="text-danger fw-bold">CRITICAL:</span> The project has exceeded the total allocated budget by <?= format_currency(abs($total_variance)) ?>. Urgent cost containment measures are required.
                    <?php elseif ($total_util > 90): ?>
                        <span class="text-warning fw-bold">WARNING:</span> Budget utilization is at <?= round($total_util) ?>%. Very limited funds remaining for the remaining project scope.
                    <?php else: ?>
                        <span class="text-success fw-bold">HEALTHY:</span> Current spending is within the allocated budget. Total savings/buffer of <?= format_currency($total_variance) ?> identified.
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-4 rounded border-start border-4 border-primary bg-light h-100">
                <h6 class="fw-bold text-uppercase small mb-3"><i class="bi bi-lightning-charge me-2"></i>Action Items</h6>
                <ul class="small text-muted ps-3 mb-0">
                    <?php if ($total_util > 85): ?>
                        <li>Re-evaluate category allocations.</li>
                        <li>Audit high-utilization categories.</li>
                    <?php else: ?>
                        <li>Continue regular spending monitoring.</li>
                        <li>Verify all vouchers are captured.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="fixed-print-footer d-none d-print-block">
        This report was <strong>Printed</strong> by <span class="text-dark fw-bold"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= $_SESSION['username'] ?? 'Staff' ?></span> 
        on <span class="text-dark fw-bold"><?= date('d M, Y \a\t H:i:s') ?></span>
        <div class="mt-1 fw-bold text-primary">Powered By BJP Technologies</div>
    </div>
</div>

</body>
</html>
