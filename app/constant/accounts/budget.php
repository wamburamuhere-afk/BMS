<?php
// scope-audit: skip — Phase G complete; custom inline scope ($b_scope_where_sql/$b_scope_on_sql/$e_scope_where_sql) applied to all budget and expense queries
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
autoEnforcePermission('budget');

includeHeader();

// Fetch company settings for print
$c_logo = getSetting('company_logo', '');
$c_name = getSetting('company_name', 'BMS');

// Check permissions using centralized system
$can_view_budget = canView('budget');
$can_edit_budget = canEdit('budget');
$can_approve_budget = canEdit('budget'); // Using edit permission for approval actions in UI for now

if (!$can_view_budget) {
    redirectTo('dashboard');
}

// Get current year and month
$current_year = date('Y');
$current_month = date('n'); // 1-12

// Get selected year and month from query parameters — 'all' means no filter
$selected_year  = isset($_GET['year'])  && $_GET['year']  !== 'all' ? intval($_GET['year'])  : 'all';
$selected_month = isset($_GET['month']) && $_GET['month'] !== 'all' ? intval($_GET['month']) : 'all';

// Validate numeric values
if ($selected_year !== 'all'  && ($selected_year  < 2020 || $selected_year  > 2030)) $selected_year  = 'all';
if ($selected_month !== 'all' && ($selected_month < 1    || $selected_month > 12))   $selected_month = 'all';

// Project scope fragments used across all budget & expense queries below
$scope_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
$b_scope_where_sql = '';        // for plain FROM budgets (no alias) — summary query
$b_scope_where_b_sql = '';      // for FROM budgets b (aliased) — performance query
$b_scope_where_params = [];
$b_scope_on_sql = '';
$b_scope_on_params = [];
$e_scope_where_sql = '';
$e_scope_where_params = [];
if (!isAdmin()) {
    if (!empty($scope_assigned)) {
        $scope_ph = implode(',', array_fill(0, count($scope_assigned), '?'));
        $b_scope_where_sql    = " AND (project_id IS NULL OR project_id IN ($scope_ph))";
        $b_scope_where_b_sql  = " AND (b.project_id IS NULL OR b.project_id IN ($scope_ph))";
        $b_scope_where_params = $scope_assigned;
        $b_scope_on_sql       = " AND (b.project_id IS NULL OR b.project_id IN ($scope_ph))";
        $b_scope_on_params    = $scope_assigned;
        $e_scope_where_sql    = " AND (e.project_id IS NULL OR e.project_id IN ($scope_ph))";
        $e_scope_where_params = $scope_assigned;
    } else {
        $b_scope_where_sql   = " AND project_id IS NULL";
        $b_scope_where_b_sql = " AND b.project_id IS NULL";
        $b_scope_on_sql      = " AND b.project_id IS NULL";
        $e_scope_where_sql   = " AND e.project_id IS NULL";
    }
}

// Build dynamic WHERE clause pieces
$where_parts  = [];
$where_params = [];
if ($selected_year  !== 'all') { $where_parts[] = 'b.budget_year  = ?'; $where_params[] = $selected_year;  }
if ($selected_month !== 'all') { $where_parts[] = 'b.budget_month = ?'; $where_params[] = $selected_month; }
$budget_where = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
if (!empty($b_scope_where_b_sql)) {
    $budget_where = $budget_where ?: 'WHERE 1=1';
    $budget_where .= $b_scope_where_b_sql;
    $where_params  = array_merge($where_params, $b_scope_where_params);
}

// For expense queries, build matching WHERE clauses
$exp_where_parts  = [];
$exp_where_params = [];
if ($selected_year  !== 'all') { $exp_where_parts[] = 'YEAR(e.expense_date)  = ?'; $exp_where_params[] = $selected_year;  }
if ($selected_month !== 'all') { $exp_where_parts[] = 'MONTH(e.expense_date) = ?'; $exp_where_params[] = $selected_month; }
$exp_date_filter = $exp_where_parts ? implode(' AND ', $exp_where_parts) . ' AND' : '';

// Fetch Projects if enabled
$enable_projects = get_setting('enable_projects');
$projects = [];
if ($enable_projects == '1') {
    if (isAdmin()) {
        $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($scope_assigned)) {
        $ph = implode(',', array_fill(0, count($scope_assigned), '?'));
        $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($ph) ORDER BY project_name ASC");
        $pstmt->execute($scope_assigned);
        $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get all expense categories for budget allocation
$cat_join_parts  = [];
$cat_join_params = [];
if ($selected_year  !== 'all') { $cat_join_parts[] = 'b.budget_year  = ?'; $cat_join_params[] = $selected_year;  }
if ($selected_month !== 'all') { $cat_join_parts[] = 'b.budget_month = ?'; $cat_join_params[] = $selected_month; }
$cat_join_on = $cat_join_parts ? ' AND ' . implode(' AND ', $cat_join_parts) : '';

$categories_stmt = $pdo->prepare("
    SELECT ec.id AS category_id, ec.name AS category_name, ec.status, ec.type_id,
           COALESCE(SUM(b.allocated_amount), 0) as allocated_amount,
           COALESCE(SUM(b.actual_amount), 0) as actual_amount,
           MAX(b.status) as budget_status
    FROM expense_categories ec
    LEFT JOIN budgets b ON ec.id = b.category_id $b_scope_on_sql $cat_join_on
    WHERE ec.status = 'active'
    GROUP BY ec.id
    ORDER BY ec.name
");
$categories_stmt->execute(array_merge($b_scope_on_params, $cat_join_params));
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build summary WHERE
$sum_where_parts  = [];
$sum_where_params = [];
if ($selected_year  !== 'all') { $sum_where_parts[] = 'budget_year  = ?'; $sum_where_params[] = $selected_year;  }
if ($selected_month !== 'all') { $sum_where_parts[] = 'budget_month = ?'; $sum_where_params[] = $selected_month; }
$sum_where = $sum_where_parts ? 'WHERE ' . implode(' AND ', $sum_where_parts) : '';
if (!empty($b_scope_where_sql)) {
    $sum_where = $sum_where ?: 'WHERE 1=1';
    $sum_where .= $b_scope_where_sql;
    $sum_where_params = array_merge($sum_where_params, $b_scope_where_params);
}

// Get budget summary for the selected period
$summary_stmt = $pdo->prepare("
    SELECT
        SUM(allocated_amount) as total_allocated,
        SUM(actual_amount) as total_actual_cached,
        COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count,
        COUNT(CASE WHEN status = 'pending'  THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'draft'    THEN 1 END) as draft_count
    FROM budgets
    $sum_where
");
$summary_stmt->execute($sum_where_params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Calculate total actual from expenses table dynamically
$total_actual_sql = "
    SELECT SUM(e.amount)
    FROM expenses e
    JOIN accounts a ON e.expense_account_id = a.account_id
    WHERE $exp_date_filter e.status IN ('approved', 'paid') $e_scope_where_sql
";
$total_actual_stmt = $pdo->prepare($total_actual_sql);
$total_actual_stmt->execute(array_merge($exp_where_params, $e_scope_where_params));
$summary['total_actual'] = $total_actual_stmt->fetchColumn() ?: 0;

// Get actual expenses grouped by category and project
$expenses_sql = "
    SELECT
        CONCAT(ec.id, '_', COALESCE(e.project_id, 0)) as key_id,
        SUM(e.amount) as total_expenses
    FROM expenses e
    JOIN accounts a ON e.expense_account_id = a.account_id
    JOIN expense_categories ec ON (a.category_id = ec.id OR a.account_name LIKE CONCAT('%', ec.name, '%'))
    WHERE $exp_date_filter e.status IN ('approved', 'paid') $e_scope_where_sql
    GROUP BY ec.id, e.project_id
";
$expenses_stmt = $pdo->prepare($expenses_sql);
$expenses_stmt->execute(array_merge($exp_where_params, $e_scope_where_params));
$actual_expenses = $expenses_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get budget performance data
$joinProjects   = ($enable_projects == '1') ? "LEFT JOIN projects p ON b.project_id = p.project_id" : "";
$selectProjects = ($enable_projects == '1') ? ", p.project_name, b.project_id" : "";

$performance_stmt = $pdo->prepare("
    SELECT
        b.budget_id,
        b.category_id,
        ec.name AS category_name,
        b.allocated_amount,
        b.status,
        b.budget_year,
        b.budget_month,
        b.line_items
        $selectProjects
    FROM budgets b
    JOIN expense_categories ec ON b.category_id = ec.id
    $joinProjects
    $budget_where
    ORDER BY ec.name
");
$performance_stmt->execute($where_params);
$performance_data = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Inject dynamic actual amounts
foreach ($performance_data as &$item) {
    $key = $item['category_id'] . '_' . ($item['project_id'] ?? 0);
    $item['actual_amount'] = $actual_expenses[$key] ?? 0;
}
unset($item);

// Months array for dropdown
$months = [
    1 => 'January', 2 => 'February', 3 => 'March',    4 => 'April',
    5 => 'May',     6 => 'June',     7 => 'July',      8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Years array
$years = [];
for ($year = $current_year - 2; $year <= $current_year + 3; $year++) {
    $years[$year] = $year;
}
?>

<style>
/* ── Mobile card view logic ── */
@media screen and (max-width: 768px) {
    /* Hide the default table header */
    #budgetTable thead {
        display: none;
    }
    
    /* Make each row look like a card */
    #budgetTable, #budgetTable tbody, #budgetTable tr, #budgetTable td {
        display: block;
        width: 100% !important;
    }
    
    #budgetTable tr {
        margin-bottom: 1rem;
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        padding: 10px;
        position: relative;
    }
    
    #budgetTable td {
        text-align: right !important;
        padding: 8px 12px !important;
        border: none !important;
        position: relative;
        display: flex;
        justify-content: space-between;
        align-items: center;
        min-height: 40px;
        border-bottom: 1px solid #f8f9fa !important;
    }

    #budgetTable td:last-child {
        border-bottom: none !important;
        margin-top: 5px;
        padding-top: 15px !important;
        justify-content: center !important;
    }

    /* Add labels before each data point */
    #budgetTable td::before {
        content: attr(data-label);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.65rem;
        color: #6c757d;
        text-align: left;
    }
    
    /* Special styling for specific cells in card view */
    #budgetTable td[data-label="Budget Name"] {
        background: #f8faff;
        border-radius: 8px 8px 0 0;
        justify-content: center !important;
        text-align: center !important;
        font-size: 1.1rem;
    }
    #budgetTable td[data-label="Budget Name"]::before { display: none; }
    
    /* Hide S/NO on mobile cards to save space */
    #budgetTable td[data-label="S/NO"] { display: none; }

    /* Compact DataTable controls on mobile */
    .dataTables_wrapper .row:first-child {
        display: none !important; /* Hide search/length on mobile if requested */
    }
    .dataTables_wrapper .row:last-child {
        margin-top: 15px;
    }
    .dataTables_info, .dataTables_paginate {
        text-align: center !important;
        font-size: 0.8rem !important;
    }
    
    /* Force no horizontal scroll */
    .table-responsive {
        overflow-x: hidden !important;
        padding: 0 !important;
    }
}
</style>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= htmlspecialchars($c_name) ?></h1>
        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Budget Performance Report</h2>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-pie-chart-fill"></i> Budget Management</h2>
                    <p class="text-muted mb-0">Plan, track, and optimize your financial resources</p>
                </div>
                <div>
                    <?php if ($can_edit_budget): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBudgetModal">
                        <i class="bi bi-plus-circle"></i> Add Budget
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function resizeTextToFit() {
            const elements = document.querySelectorAll('.custom-stat-card h4.auto-resize');
            elements.forEach(el => {
                let size = 1.3; // Starting size
                el.style.fontSize = size + 'rem';
                const container = el.closest('.overflow-hidden');
                if (container) {
                    const containerWidth = container.clientWidth;
                    while (el.scrollWidth > containerWidth && size > 0.7) {
                        size -= 0.05;
                        el.style.fontSize = size + 'rem';
                    }
                }
            });
        }
        window.addEventListener('load', resizeTextToFit);
        window.addEventListener('resize', resizeTextToFit);
    </script>

    <!-- Budget Summary Cards -->
    <div class="row mb-4" id="print-stats-cards">
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3 d-none d-sm-flex">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Total Allocated</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" style="font-size: 1.1rem;"><?= number_format($summary['total_allocated'] ?? 0, 2) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3 d-none d-sm-flex">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Total Actual</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" style="font-size: 1.1rem;"><?= number_format($summary['total_actual'] ?? 0, 2) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3 d-none d-sm-flex">
                            <i class="bi bi-graph-up-arrow"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Variance</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" style="font-size: 1.1rem;"><?= number_format(($summary['total_allocated'] ?? 0) - ($summary['total_actual'] ?? 0), 2) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3 d-none d-sm-flex">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Categories</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" style="font-size: 1.1rem;"><?= $summary['approved_count'] ?? 0 ?> / <?= count($categories) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Period Selector -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-calendar"></i> Select Period</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-6 col-md-5">
                    <label class="form-label small fw-bold">Year</label>
                    <select class="form-select form-select-sm" name="year" onchange="this.form.submit()">
                        <option value="all" <?= $selected_year === 'all' ? 'selected' : '' ?>>— All —</option>
                        <?php foreach ($years as $year => $year_label): ?>
                        <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>>
                            <?= $year_label ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-5">
                    <label class="form-label small fw-bold">Month</label>
                    <select class="form-select form-select-sm" name="month" onchange="this.form.submit()">
                        <option value="all" <?= $selected_month === 'all' ? 'selected' : '' ?>>— All —</option>
                        <?php foreach ($months as $month_num => $month_name): ?>
                        <option value="<?= $month_num ?>" <?= $month_num == $selected_month ? 'selected' : '' ?>>
                            <?= $month_name ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="bi bi-filter"></i> Apply
                    </button>
                    <a href="<?= getUrl('budget') ?>" class="btn btn-outline-secondary btn-sm flex-fill text-center" style="line-height:1.8;">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <!-- Compact Export Buttons -->
            <div class="btn-group btn-group-sm shadow-sm border rounded bg-white">
                <button type="button" class="btn btn-light border-0 px-2" onclick="copyTable()" title="Copy to clipboard">
                    <i class="bi bi-clipboard text-info"></i> Copy
                </button>
                <button type="button" class="btn btn-light border-0 px-2 border-start" onclick="exportBudget()" title="Export to Excel">
                    <i class="bi bi-file-earmark-excel text-success"></i> Excel
                </button>
                <button type="button" class="btn btn-light border-0 px-2 border-start" onclick="printBudget()" title="Print Report">
                    <i class="bi bi-printer text-primary"></i> Print
                </button>
            </div>
            
            <!-- Compact Page Length -->
            <div class="d-flex align-items-center bg-white shadow-sm border rounded px-2 py-1" style="height: 31px;">
                <span class="small text-muted me-1" style="font-size: 0.75rem;">Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 45px; box-shadow: none; background: transparent; font-size: 0.75rem;" onchange="$('#budgetTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>

            <!-- Search -->
            <div class="input-group input-group-sm shadow-sm border rounded bg-white overflow-hidden" style="width: 200px;">
                <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted small"></i></span>
                <input type="text" class="form-control border-0 p-1 small" id="budgetSearch" placeholder="Search..." onkeyup="$('#budgetTable').DataTable().search(this.value).draw();">
            </div>
        </div>

        <div>
            <span class="badge bg-success-soft text-success border border-success px-2 py-1 rounded-pill" style="font-size: 0.75rem;">
                <i class="bi bi-check-circle-fill me-1"></i> <?= count($performance_data) ?> Categories
            </span>
        </div>
    </div>

    <!-- Budget Performance Table -->
    <div class="card mb-4">
        <div class="card-header custom-table-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Budget Performance — <?= ($selected_month !== 'all' ? $months[$selected_month] : 'All Months') ?> <?= ($selected_year !== 'all' ? $selected_year : 'All Years') ?></h5>
                <div class="d-flex">
                    <span class="badge bg-light text-dark me-2">
                        <?= count($performance_data) ?> categories
                    </span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($performance_data) > 0): ?>
                <div class="table-responsive">
                    <table id="budgetTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th style="width:50px;">S/NO</th>
                                <th>Budget Name</th>
                                <th style="width:110px;">Type</th>
                                <?php if ($enable_projects == '1'): ?>
                                <th>Project</th>
                                <?php endif; ?>
                                <th>Allocated Amount</th>
                                <th>Actual Amount</th>
                                <th>Variance</th>
                                <th>Variance %</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn = 1; foreach ($performance_data as $item): 
                                $variance = $item['allocated_amount'] - $item['actual_amount'];
                                $usage_percentage = $item['allocated_amount'] > 0 ? 
                                    (($item['actual_amount'] / $item['allocated_amount']) * 100) : 0;
                            ?>
                            <?php
                            $budget_is_service = false;
                            if (!empty($item['line_items'])) {
                                $li_decoded = json_decode($item['line_items'], true);
                                if (is_array($li_decoded) && isset($li_decoded['is_service'])) {
                                    $budget_is_service = (bool)$li_decoded['is_service'];
                                }
                            }
                            ?>
                            <tr>
                                <td class="text-center text-muted small fw-bold" data-label="S/NO"><?= $sn++ ?></td>
                                <td data-label="Budget Name">
                                    <strong><?= htmlspecialchars($item['category_name']) ?></strong>
                                </td>
                                <td data-label="Type">
                                    <?php if ($budget_is_service): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25" style="font-size:0.7rem;">Non-Inventory</span>
                                    <?php else: ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25" style="font-size:0.7rem;">Inventory</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($enable_projects == '1'): ?>
                                <td data-label="Project">
                                    <?php if (!empty($item['project_name'])): ?>
                                        <span class="badge bg-primary-soft text-primary border border-primary"><?= htmlspecialchars($item['project_name']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td data-label="Allocated Amount">
                                    <strong><?= number_format($item['allocated_amount'], 2) ?></strong>
                                </td>
                                <td data-label="Actual Amount">
                                    <strong class="text-primary"><?= number_format($item['actual_amount'], 2) ?></strong>
                                </td>
                                <td data-label="Variance">
                                    <span class="badge bg-<?= get_variance_color($variance) ?>">
                                        <?= number_format($variance, 2) ?>
                                    </span>
                                </td>
                                <td data-label="Variance %">
                                    <span class="badge bg-<?= $usage_percentage > 100 ? 'danger' : 'info' ?>">
                                        <?= number_format($usage_percentage, 1) ?>%
                                    </span>
                                </td>
                                <td data-label="Status">
                                    <span class="badge bg-<?= get_status_badge($item['status']) ?>">
                                        <?= ucfirst($item['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end" data-label="Actions">
                                    <!-- Desktop: Gear Dropdown -->
                                    <div class="dropdown action-dropdown d-none d-md-block">
                                        <button class="btn btn-light btn-sm dropdown-toggle shadow-sm border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill text-primary"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="viewBudgetDetails(<?= $item['category_id'] ?>, <?= $item['budget_year'] ?>, <?= $item['budget_month'] ?>)">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <?php if ($can_edit_budget): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editBudget(<?= $item['budget_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit Budget
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_approve_budget && $item['status'] == 'pending'): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="updateBudgetStatus(<?= $item['budget_id'] ?>, 'approved')">
                                                    <i class="bi bi-check-circle text-success"></i> Approve
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="updateBudgetStatus(<?= $item['budget_id'] ?>, 'rejected')">
                                                    <i class="bi bi-x-circle text-danger"></i> Reject
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_budget): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDeleteBudget(<?= $item['budget_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>

                                    <!-- Mobile: Direct Buttons (No Dropdown) -->
                                    <div class="d-flex d-md-none justify-content-center flex-wrap gap-2">
                                        <button class="btn btn-sm btn-outline-primary shadow-sm" onclick="viewBudgetDetails(<?= $item['category_id'] ?>, <?= $item['budget_year'] ?>, <?= $item['budget_month'] ?>)" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($can_edit_budget): ?>
                                        <button class="btn btn-sm btn-outline-info shadow-sm" onclick="editBudget(<?= $item['budget_id'] ?>)" title="Edit Budget">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_approve_budget && $item['status'] == 'pending'): ?>
                                        <button class="btn btn-sm btn-outline-success shadow-sm" onclick="updateBudgetStatus(<?= $item['budget_id'] ?>, 'approved')" title="Approve">
                                            <i class="bi bi-check-circle"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger shadow-sm" onclick="updateBudgetStatus(<?= $item['budget_id'] ?>, 'rejected')" title="Reject">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_edit_budget): ?>
                                        <button class="btn btn-sm btn-outline-danger shadow-sm" onclick="confirmDeleteBudget(<?= $item['budget_id'] ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-pie-chart" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Budget Data Found</h4>
                    <p class="text-muted">No budget has been created for <?= ($selected_month !== 'all' ? $months[$selected_month] : 'All Months') ?> <?= ($selected_year !== 'all' ? $selected_year : 'All Years') ?>.</p>
                    <?php if ($can_edit_budget): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addBudgetModal">
                        <i class="bi bi-plus-circle"></i> Create Budget
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header custom-table-header bg-light">
                    <h5 class="mb-0">Budget Allocation by Category</h5>
                </div>
                <div class="card-body">
                    <canvas id="budgetChart" style="height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header custom-table-header bg-light">
                    <h5 class="mb-0">Budget vs Actual Comparison</h5>
                </div>
                <div class="card-body">
                    <canvas id="comparisonChart" style="height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Unbudgeted Categories -->
    <?php if ($can_edit_budget): 
        $unbudgeted = array_filter($categories, function($cat) {
            return empty($cat['allocated_amount']) || $cat['allocated_amount'] == 0;
        });
        
        if (count($unbudgeted) > 0): ?>
    <div class="card">
        <div class="card-header custom-table-header bg-light">
            <h5 class="mb-0">Unbudgeted Categories</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">The following categories have no budget allocated for <?= ($selected_month !== 'all' ? $months[$selected_month] : 'All Months') ?> <?= ($selected_year !== 'all' ? $selected_year : 'All Years') ?>:</p>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($unbudgeted as $category): ?>
                <span class="badge bg-secondary">
                    <?= htmlspecialchars($category['category_name']) ?>
                    <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.6rem;" onclick="quickAddBudget(<?= $category['category_id'] ?>, '<?= htmlspecialchars($category['category_name']) ?>')"></button>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; endif; ?>
</div>

<!-- Add Budget Modal -->
<?php if ($can_edit_budget): ?>
<div class="modal fade" id="addBudgetModal" tabindex="-1" aria-labelledby="addBudgetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addBudgetModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Budget
                </h5>
                <div class="form-check form-switch ms-3 mb-0">
                    <input class="form-check-input" type="checkbox" id="budget_is_service" onchange="toggleBudgetServiceMode()">
                    <label class="form-check-label fw-bold text-white small" for="budget_is_service">
                        <i class="bi bi-box-seam me-1"></i> Non-Inventory
                    </label>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addBudgetForm">
                <div class="modal-body">
                    <div id="add-budget-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="budget_year" class="form-label">Year <span class="text-danger">*</span></label>
                            <select class="form-select" id="budget_year" name="budget_year" required>
                                <?php foreach ($years as $year => $year_label): ?>
                                <option value="<?= $year ?>" <?= $year == $selected_year ? 'selected' : '' ?>>
                                    <?= $year_label ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="budget_month" class="form-label">Month <span class="text-danger">*</span></label>
                            <select class="form-select" id="budget_month" name="budget_month" required>
                                <?php foreach ($months as $month_num => $month_name): ?>
                                <option value="<?= $month_num ?>" <?= $month_num == $selected_month ? 'selected' : '' ?>>
                                    <?= $month_name ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="budget_name" class="form-label">Budget Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="budget_name" name="budget_name" required placeholder="e.g. Office Rent, Marketing, etc.">
                        </div>
                        <?php if ($enable_projects == '1'): ?>
                        <div class="col-md-6 mb-3">
                            <label for="project_id" class="form-label">Project</label>
                            <select class="form-select" id="project_id" name="project_id">
                                <option value="">Select Project</option>
                                <?php foreach ($projects as $proj): ?>
                                <option value="<?= $proj['project_id'] ?>">
                                    <?= htmlspecialchars($proj['project_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12 mb-4">
                            <hr>
                            <h6 class="fw-bold mb-3"><i class="bi bi-list-check"></i> Budget Breakdown (Items)</h6>
                            <div class="border rounded overflow-hidden">
                                <table class="table table-sm table-bordered mb-0" id="budgetItemsTable" style="table-layout: fixed; width: 100%;">
                                    <thead class="bg-light">
                                        <tr>
                                            <th style="width: 45px; white-space: nowrap;" class="text-center">S/No</th>
                                            <th style="white-space: nowrap;">Description <span class="text-danger">*</span></th>
                                            <th style="width: 70px; white-space: nowrap;" class="budget-svc-col">Units</th>
                                            <th style="width: 55px; white-space: nowrap;" class="text-center budget-svc-col">Qty</th>
                                            <th style="width: 90px; white-space: nowrap;" class="text-end">Price</th>
                                            <th style="width: 65px; white-space: nowrap;" class="text-end">Tax %</th>
                                            <th style="width: 100px; white-space: nowrap;" class="text-end budget-svc-col">Total</th>
                                            <th style="width: 40px; white-space: nowrap;" class="text-center"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Items will be added here -->
                                    </tbody>
                                    <tfoot class="bg-light fw-bold">
                                        <tr>
                                            <td colspan="6" class="text-end" id="budgetGrandTotalLabel">Grand Total Allocated (TZS):</td>
                                            <td class="text-end text-primary" id="budgetGrandTotal">0.00</td>
                                            <td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <!-- Product Search -->
                            <div class="d-flex align-items-center gap-2 mt-2 mb-1">
                                <div class="position-relative flex-grow-1">
                                    <input type="text" id="budgetProductSearch" class="form-control form-control-sm" placeholder="Search product to add..." autocomplete="off" oninput="searchBudgetProducts(this.value)">
                                    <div id="budgetProductResults" class="position-absolute bg-white border rounded shadow-lg w-100" style="z-index:9999; display:none; max-height:220px; overflow-y:auto; top:100%;"></div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-1" onclick="addBudgetRow()">
                                <i class="bi bi-plus-circle"></i> Add Item Manually
                            </button>
                            <input type="hidden" id="allocated_amount" name="allocated_amount" value="0">
                            <input type="hidden" id="budget_is_service_value" name="budget_is_service_value" value="0">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Additional notes about this budget allocation"></textarea>
                        </div>
                        <div class="col-md-6 mb-3" style="display: none;">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <?php if ($can_approve_budget): ?>
                                <option value="approved">Approved</option>
                                <?php endif; ?>
                                <option value="pending">Pending</option>
                                <option value="draft" selected>Draft</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Budget
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chart.js Library (Loaded here as it is only needed on this page) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Budget List', 'User viewed budget management for <?= ($selected_month !== 'all' ? $months[$selected_month] : 'All Months') ?> <?= ($selected_year !== 'all' ? $selected_year : 'All Years') ?>');

    // Initialize DataTable
    if ($('#budgetTable').length) {
        $('#budgetTable').DataTable({
            language: {
                search: "Search budget:",
                lengthMenu: "Show _MENU_ records per page",
                info: "Showing _START_ to _END_ of _TOTAL_ budgets",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            },
            responsive: false, // Disable built-in responsive to use our custom card view
            autoWidth: false,
            pageLength: 25,
            dom: 'rtipB', // 'B' is included but buttons are hidden by default
            buttons: [
                {
                    extend: 'copy',
                    className: 'd-none',
                    exportOptions: { columns: ':not(:last-child)' }
                },
                {
                    extend: 'excel',
                    className: 'd-none',
                    filename: 'Budget_Performance_Report_<?= date('Y-m-d') ?>',
                    title: 'BUDGET PERFORMANCE REPORT - <?= ($selected_month !== 'all' ? strtoupper($months[$selected_month]) : 'ALL MONTHS') ?> <?= ($selected_year !== 'all' ? $selected_year : 'ALL YEARS') ?>',
                    exportOptions: { columns: ':not(:last-child)' }
                },
                {
                    extend: 'print',
                    className: 'd-none',
                    title: '',
                    exportOptions: { columns: ':not(:last-child)' },
                    customize: function (win) {
                        $(win.document.body).css('font-family', 'Inter, sans-serif');
                        $(win.document.body).find('table').addClass('compact').css('font-size', '10pt');
                        $(win.document.body).prepend(`
                            <div style="text-align:center; padding: 20px 0; border-bottom: 3px solid #0d6efd; margin-bottom: 20px;">
                                <?php if(!empty($c_logo)): ?>
                                    <div class="mb-3">
                                        <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                                    </div>
                                <?php endif; ?>
                                <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= htmlspecialchars($c_name) ?></h1>
                                <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Budget Performance Report</h2>
                                <h3 style="color: #6c757d; margin: 0; font-size: 12pt;">Period: <?= ($selected_month !== 'all' ? $months[$selected_month] : 'All Months') ?> <?= ($selected_year !== 'all' ? $selected_year : 'All Years') ?></h3>
                                <p style="color: #858796; margin: 5px 0 0 0; font-size: 10pt;">Generated on: ${new Date().toLocaleString()}</p>
                            </div>
                        `);
                        $(win.document.body).find('table thead th').css({
                            'background-color': '#4e73df',
                            'color': 'white',
                            'text-transform': 'uppercase',
                            'padding': '10px'
                        });
                        $(win.document.body).find('table tr td').css('padding', '8px');

                        // ── Standard System Print Footer ──────────────────────────
                        const now = new Date();
                        const printDate = now.getDate().toString().padStart(2,'0') + ' ' +
                            ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][now.getMonth()] +
                            ' ' + now.getFullYear() + ' at ' +
                            now.getHours().toString().padStart(2,'0') + ':' +
                            now.getMinutes().toString().padStart(2,'0') + ':' +
                            now.getSeconds().toString().padStart(2,'0');

                        $(win.document.body).append(`
                            <style>
                                @page { margin-bottom: 5mm; }
                                @media print {
                                    html, body { height: 100%; margin: 0 !important; padding: 0 !important; }
                                    .bms-print-footer {
                                        display: flex !important;
                                        position: fixed !important;
                                        bottom: 0 !important;
                                        left: 0 !important;
                                        right: 0 !important;
                                        width: 100% !important;
                                        height: 12mm !important;
                                        padding: 1mm 10mm !important;
                                        border-top: 1px solid #ddd !important;
                                        background: #fff !important;
                                        flex-direction: column !important;
                                        align-items: center !important;
                                        text-align: center !important;
                                        z-index: 9999 !important;
                                        -webkit-print-color-adjust: exact !important;
                                        print-color-adjust: exact !important;
                                    }
                                    .bpf-text { font-size: 8.5pt !important; color: #444 !important; margin: 0 !important; line-height: 1.2 !important; display: block !important; }
                                    .bpf-blue { color: #0d6efd !important; font-weight: 700 !important; }
                                }
                            </style>
                            <div class="bms-print-footer">
                                <span class="bpf-text">
                                    This document was <strong>Printed</strong> by <strong><?= htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= htmlspecialchars($_SESSION['user_role'] ?? 'User') ?></strong> on ${printDate}
                                </span>
                                <span class="bpf-text bpf-blue">
                                    Powered by BJP Technologies &copy; ${now.getFullYear()}, All Rights Reserved.
                                </span>
                            </div>
                        `);

                        // Table buffer so content never hides behind footer
                        $(win.document.body).find('table').append(`
                            <tfoot style="display: table-footer-group !important;">
                                <tr><td colspan="100%" style="height: 15mm; border: none !important; background: transparent !important;"></td></tr>
                            </tfoot>
                        `);
                    }
                }
            ]
        });
    }

    // Initialize charts
    initializeCharts();

    // Add budget form submission
    $('#addBudgetForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: '/api/add_budget.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#add-budget-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-budget-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Budget');
                }
            },
            error: function() {
                $('#add-budget-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Budget');
            }
        });
    });

    // Reset form when modal is closed
    $('#addBudgetModal').on('hidden.bs.modal', function() {
        // Rename back to addBudgetForm if it was changed during edit
        if ($('#editBudgetForm').length) {
            $('#editBudgetForm').attr('id', 'addBudgetForm');
        }
        const $form = $('#addBudgetForm');
        if ($form[0]) $form[0].reset();
        $('#add-budget-message').html('');
        $form.find('[type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Budget');
        $form.off('submit');
        $('#addBudgetModalLabel').html('<i class="bi bi-plus-circle"></i> Add New Budget');
        $('#budgetItemsTable tbody').empty();
        $('#budget_is_service').prop('checked', false).closest('.form-check').show();
        applyBudgetServiceMode(false);
    });
});

function initializeCharts() {
    // Prepare data for charts
    const performanceData = <?= json_encode($performance_data) ?>;
    
    if (performanceData.length === 0) return;
    
    // Budget Allocation Pie Chart
    const labels = performanceData.map(item => {
        return item.project_name ? `${item.category_name} - ${item.project_name}` : item.category_name;
    });
    const allocatedData = performanceData.map(item => item.allocated_amount);
    
    const budgetCtx = document.getElementById('budgetChart');
    if (budgetCtx) {
        new Chart(budgetCtx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: allocatedData,
                    backgroundColor: [
                        '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', 
                        '#e74a3b', '#858796', '#6f42c1', '#20c9a6'
                    ],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Budget vs Actual Comparison Chart
    const actualData = performanceData.map(item => item.actual_amount);
    
    const comparisonCtx = document.getElementById('comparisonChart');
    if (comparisonCtx) {
        new Chart(comparisonCtx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Allocated',
                        data: allocatedData,
                        backgroundColor: 'rgba(78, 115, 223, 0.8)',
                        borderColor: 'rgba(78, 115, 223, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Actual',
                        data: actualData,
                        backgroundColor: 'rgba(28, 200, 138, 0.8)',
                        borderColor: 'rgba(28, 200, 138, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${context.parsed.y.toLocaleString()}`;
                            }
                        }
                    }
                }
            }
        });
    }
}

function viewBudgetDetails(categoryId, year, month) {
    logReportAction('Viewed Budget Details Link', 'User clicked to view budget details for category #' + categoryId + ' for ' + year + '-' + month);
    // Use the specific year and month passed from the row
    window.location.href = '<?= getUrl('budget/details') ?>?category_id=' + categoryId + 
                          '&year=' + year + '&month=' + month;
}

function updateBudgetStatus(budgetId, status) {
    const action = status === 'approved' ? 'approve' : 'reject';
    
    Swal.fire({
        title: 'Are you sure?',
        text: "You want to " + action + " this budget record?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: status === 'approved' ? '#1cc88a' : '#e74a3b',
        confirmButtonText: 'Yes, ' + action + ' it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '/api/update_budget_status.php',
                type: 'POST',
                data: { 
                    budget_id: budgetId,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Updated Budget Status', 'User updated budget status for record #' + budgetId + ' to ' + status);
                        Swal.fire({
                            icon: 'success',
                            title: 'Status Updated',
                            text: response.message,
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Error updating status. Please try again.', 'error');
                }
            });
        }
    });
}

function confirmDeleteBudget(budgetId) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '/api/delete_budget.php',
                method: 'POST',
                data: { 
                    budget_id: budgetId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Deleted Budget Record', 'User deleted budget record #' + budgetId);
                        Swal.fire('Deleted!', response.message, 'success').then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Error deleting budget. Please try again.', 'error');
                }
            });
        }
    });
}

function quickAddBudget(categoryId, categoryName) {
    $('#budget_name').val(categoryName);
    $('#budget_year').val(<?= $selected_year ?>);
    $('#budget_month').val(<?= $selected_month ?>);
    
    // Auto-focus on amount field
    $('#allocated_amount').focus();
    
    $('#addBudgetModal').modal('show');
}

function exportBudget() {
    if ($('#budgetTable').length) {
        logReportAction('Exported Budget List', 'User exported budget performance table to Excel');
        $('#budgetTable').DataTable().button('.buttons-excel').trigger();
    }
}

function copyTable() {
    if ($('#budgetTable').length) {
        const table = document.getElementById('budgetTable');
        const range = document.createRange();
        range.selectNode(table);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        document.execCommand('copy');
        window.getSelection().removeAllRanges();

        logReportAction('Copied Budget Table', 'User copied budget performance table to clipboard');
        
        Swal.fire({ 
            icon: 'success', 
            title: 'Copied!', 
            text: 'Table copied to clipboard', 
            timer: 1500, 
            showConfirmButton: false 
        });
    }
}

function printBudget() {
    if ($('#budgetTable').length) {
        logReportAction('Printed Budget Table', 'User generated a printed budget performance report');
        $('#budgetTable').DataTable().button('.buttons-print').trigger();
    }
}

// Budget Items Management
function addBudgetRow(desc = '', units = '', qty = 1, price = 0, tax = 0) {
    qty = parseFloat(qty) || 1;
    price = parseFloat(price) || 0;
    tax = parseFloat(tax) || 0;
    const rowCount = $('#budgetItemsTable tbody tr').length + 1;
    const rowTotal = qty * price * (1 + tax / 100);

    const row = `
        <tr>
            <td class="text-center align-middle sno">${rowCount}</td>
            <td>
                <textarea name="item_desc[]" class="form-control form-control-sm auto-expand" rows="1" required placeholder="Description" oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'">${desc}</textarea>
            </td>
            <td class="align-middle px-1 budget-svc-col"><input type="text" name="item_units[]" class="form-control form-control-sm" value="${units}" placeholder="unit"></td>
            <td class="align-middle px-1 budget-svc-col"><input type="number" name="item_qty[]" class="form-control form-control-sm text-center line-qty px-0" value="${qty}" step="0.01" min="0.01" oninput="calcRowTotal(this)" required></td>
            <td class="align-middle px-1"><input type="number" name="item_price[]" class="form-control form-control-sm text-end line-price px-0" value="${price}" step="0.01" min="0" oninput="calcRowTotal(this)" required></td>
            <td class="align-middle px-1"><input type="number" name="item_tax[]" class="form-control form-control-sm text-end line-tax px-0" value="${tax}" step="0.01" min="0" max="100" oninput="calcRowTotal(this)" placeholder="0"></td>
            <td class="align-middle px-1 budget-svc-col"><input type="number" name="item_total[]" class="form-control form-control-sm text-end line-total bg-light px-0" value="${rowTotal.toFixed(2)}" readonly></td>
            <td class="text-center align-middle" style="padding: 0;">
                <div class="d-flex justify-content-center align-items-center">
                    <button type="button" class="btn btn-sm text-danger p-0 delete-row-btn" onclick="removeBudgetRow(this)"><i class="bi bi-trash"></i></button>
                </div>
            </td>
        </tr>
    `;
    $('#budgetItemsTable tbody').append(row);
    updateGrandTotal();
}

function removeBudgetRow(btn) {
    $(btn).closest('tr').remove();
    renumberRows();
    updateGrandTotal();
}

function renumberRows() {
    $('#budgetItemsTable tbody tr').each(function(index) {
        $(this).find('.sno').text(index + 1);
    });
}

function calcRowTotal(input) {
    const $row = $(input).closest('tr');
    const qty = parseFloat($row.find('.line-qty').val()) || 0;
    const price = parseFloat($row.find('.line-price').val()) || 0;
    const tax = parseFloat($row.find('.line-tax').val()) || 0;
    const total = qty * price * (1 + tax / 100);
    $row.find('.line-total').val(total.toFixed(2));
    updateGrandTotal();
}

function updateGrandTotal() {
    let grandTotal = 0;
    // Always sum line-total as columns remain visible
    $('.line-total').each(function() { grandTotal += parseFloat($(this).val()) || 0; });
    $('#budgetGrandTotal').text(grandTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
    $('#allocated_amount').val(grandTotal);
}

function applyBudgetServiceMode(isService) {
    // Keep columns visible even if isService is true
    $('.budget-svc-col').show();
    $('#budgetGrandTotalLabel').attr('colspan', '6');
    $('#budget_is_service_value').val(isService ? '1' : '0');
}

function toggleBudgetServiceMode() {
    const isService = $('#budget_is_service').is(':checked');
    applyBudgetServiceMode(isService);
    // Clear rows and reset search when user clicks the toggle
    $('#budgetItemsTable tbody').empty();
    $('#budgetProductSearch').val('');
    $('#budgetProductResults').hide();
    addBudgetRow();
    updateGrandTotal();
}

// Initialize with one empty row when adding new budget
$('#addBudgetModal').on('shown.bs.modal', function() {
    if ($('#budgetItemsTable tbody tr').length === 0 && $('#addBudgetForm').length > 0) {
        addBudgetRow();
    }
});


// ── Non-Inventory Product Search for Budget ──
let budgetProductsCache = [];

function clearBudgetSearch() {
    $('#budgetProductSearch').val('');
    $('#budgetProductResults').hide();
}



function searchBudgetProducts(term) {
    const $results = $('#budgetProductResults');
    if (!term || term.length < 1) { $results.hide(); return; }
    const isService = $('#budget_is_service').is(':checked') ? 1 : 0;
    $results.html('<div class="p-2 text-center"><span class="spinner-border spinner-border-sm text-primary"></span></div>').show();
    $.ajax({
        url: APP_URL + '/api/account/get_products.php',
        type: 'GET',
        data: { active_only: true, is_service: isService, search: term, limit: 20 },
        dataType: 'json',
        success: function(res) {
            if (res.success && res.data && res.data.length > 0) {
                budgetProductsCache = res.data;
                let html = '';
                res.data.forEach(function(p) {
                    html += `<div class="px-3 py-2 border-bottom budget-product-item" style="cursor:pointer;" onmousedown="selectBudgetProduct(${p.product_id})">
                        <div class="fw-bold small">${p.product_name}</div>
                        <div class="text-muted" style="font-size:0.75rem;">${p.unit || '—'} &bull; TZS ${parseFloat(p.selling_price || 0).toLocaleString(undefined, {minimumFractionDigits:2})}</div>
                    </div>`;
                });
                $results.html(html).show();
            } else {
                $results.html('<div class="p-2 text-center text-muted small">No products found</div>').show();
            }
        },
        error: function() {
            $results.html('<div class="p-2 text-center text-danger small">Error fetching products</div>').show();
        }
    });
}

function selectBudgetProduct(productId) {
    const p = budgetProductsCache.find(function(x) { return x.product_id == productId; });
    if (!p) return;
    
    // If it's a service, offer to explode it
    if (p.is_service == 1) {
        Swal.fire({
            title: 'Service Assembly Detected',
            html: `Do you want to add <strong>"${p.product_name}"</strong> as a single item, or explode it into its material components for a detailed budget?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-layers me-1"></i> Explode Components',
            cancelButtonText: '<i class="bi bi-list me-1"></i> Add Single Line',
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d'
        }).then((result) => {
            if (result.isConfirmed) {
                // Fetch and add components
                fetchComponentsAndAddToBudget(p.product_id, p.product_name);
            } else {
                addBudgetRow(p.product_name, p.unit || '', 1, parseFloat(p.selling_price) || 0);
            }
        });
    } else {
        addBudgetRow(p.product_name, p.unit || '', 1, parseFloat(p.selling_price) || 0);
    }
    
    $('#budgetProductSearch').val('');
    $('#budgetProductResults').hide();
}

function fetchComponentsAndAddToBudget(productId, serviceName) {
    Swal.fire({
        title: 'Exploding Assembly...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: '/api/get_nip_components.php',
        type: 'GET',
        data: { id: productId },
        dataType: 'json',
        success: function(res) {
            Swal.close();
            if (res.success && res.data && res.data.length > 0) {
                // Remove the empty placeholder row if it's the only one
                if ($('#budgetItemsTable tbody tr').length === 1 && !$('#budgetItemsTable tbody tr:first textarea').val()) {
                    $('#budgetItemsTable tbody tr:first').remove();
                }

                res.data.forEach(function(c) {
                    // We use the component's qty_per_unit as the budget qty
                    addBudgetRow(c.product_name, c.unit || '', c.qty_per_unit, 0);
                });
                
                showToast('success', `Added ${res.data.length} components for ${serviceName}`);
            } else {
                Swal.fire('Notice', 'This service has no material components defined. Adding as single line instead.', 'info');
                addBudgetRow(serviceName, '', 1, 0);
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to fetch components. Please try again.', 'error');
        }
    });
}

$(document).on('click', function(e) {
    if (!$(e.target).closest('#budgetProductSearch, #budgetProductResults').length) {
        $('#budgetProductResults').hide();
    }
});

function editBudget(budgetId) {
    logReportAction('Initiated Budget Edit', 'User clicked edit for budget #' + budgetId);
    
    // Clear existing items in modal first
    $('#budgetItemsTable tbody').empty();
    
    $.ajax({
        url: '/api/get_budget.php',
        type: 'GET',
        data: { 
            budget_id: budgetId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate base fields
                $('#budget_year').val(response.data.budget_year);
                $('#budget_month').val(response.data.budget_month);
                $('#budget_name').val(response.data.category_name);
                $('#notes').val(response.data.notes);
                $('#status').val(response.data.status);
                
                // Set project if exists
                if (response.data.project_id && $('#project_id').length) {
                    $('#project_id').val(response.data.project_id);
                } else if ($('#project_id').length) {
                    $('#project_id').val('');
                }

                // Load line items — detect is_service flag from wrapper format
                if (response.data.line_items) {
                    try {
                        const parsed = JSON.parse(response.data.line_items);
                        // Detect format: new = {is_service, items:[]} / old = [{desc,...}]
                        let isService = false;
                        let items = [];
                        if (Array.isArray(parsed)) {
                            items = parsed; // old format, not service
                        } else if (parsed && typeof parsed === 'object') {
                            isService = parsed.is_service == 1;
                            items = parsed.items || [];
                        }
                        // Apply correct mode BEFORE adding rows
                        $('#budget_is_service').prop('checked', isService);
                        applyBudgetServiceMode(isService);

                        if (Array.isArray(items) && items.length > 0) {
                            items.forEach(item => {
                                addBudgetRow(item.desc, item.units, item.qty, item.price, item.tax_rate || 0);
                            });
                            // Trigger auto-expand for all textareas after population
                            setTimeout(() => {
                                $('.auto-expand').each(function() {
                                    this.style.height = '';
                                    this.style.height = this.scrollHeight + 'px';
                                });
                            }, 200);
                        } else {
                            addBudgetRow();
                        }
                    } catch(e) { 
                        console.error("Error parsing items", e);
                        addBudgetRow();
                    }
                } else {
                    // Fallback: one row with the total amount if no breakdown found
                    addBudgetRow(response.data.category_name, 'unit', 1, response.data.allocated_amount);
                }
                
                // Change modal to edit mode — hide the Non-Inventory toggle (mode is fixed from saved data)
                $('#addBudgetModalLabel').html('<i class="bi bi-pencil"></i> Edit Budget');
                $('#budget_is_service').closest('.form-check').hide();
                $('#addBudgetForm').attr('id', 'editBudgetForm');
                
                // Add or update hidden budget_id
                if ($('#edit_budget_id_hidden').length === 0) {
                    $('#editBudgetForm').append('<input type="hidden" id="edit_budget_id_hidden" name="budget_id" value="' + response.data.budget_id + '">');
                } else {
                    $('#edit_budget_id_hidden').val(response.data.budget_id);
                }
                
                $('#editBudgetForm [type="submit"]').html('<i class="bi bi-check-circle"></i> Update Budget');
                
                // Update form submission handler
                $('#editBudgetForm').off('submit').on('submit', function(e) {
                    e.preventDefault();
                    updateBudget(response.data.budget_id, $(this).serialize());
                });
                
                $('#addBudgetModal').modal('show');
            } else {
                Swal.fire('Error', 'Error loading budget data: ' + response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Error loading budget data. Please try again.', 'error');
        }
    });
}

function updateBudget(budgetId, formData) {
    const submitBtn = $('#editBudgetForm [type="submit"]');
    
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

    $.ajax({
        url: '/api/update_budget.php',
        type: 'POST',
        data: formData + '&budget_id=' + budgetId,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Updated!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', response.message, 'error');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Budget');
            }
        },
        error: function() {
            Swal.fire('Error', 'An error occurred. Please try again.', 'error');
            submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Budget');
        }
    });
}

</script>

<style>
.premium-header-card {
    background: white;
    border-left: 5px solid #4e73df;
    border-radius: 15px;
}

.text-gradient {
    background: linear-gradient(45deg, #4e73df, #224abe);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.icon-box {
    background: linear-gradient(135deg, #4e73df, #224abe);
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(78, 115, 223, 0.3);
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.stat-icon-circle {
    width: 48px;
    height: 48px;
    background: rgba(15, 81, 50, 0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.custom-table-header { border-bottom: 2px solid #e9ecef; }
#budgetTable thead th { font-weight: 600; border-bottom: none; }

.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 180px;
}

.action-dropdown .dropdown-item {
    padding: 0.25rem 1rem;
}

.action-dropdown .dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-info,
.card.bg-warning {
    border: none;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    #budgetTable_wrapper .row:last-child {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        margin-top: 10px;
    }
    #budgetTable_wrapper .row:last-child > div {
        width: auto !important;
        flex: 1 !important;
        padding: 0 5px !important;
        display: flex !important;
        align-items: center !important;
    }
    .dataTables_info {
        font-size: 0.65rem !important;
        white-space: nowrap !important;
    }
    .pagination {
        font-size: 0.65rem !important;
        margin-bottom: 0 !important;
    }
    .page-link {
        padding: 0.2rem 0.4rem !important;
    }
    #budgetChart, #comparisonChart {
        height: 220px !important;
    }
}

/* Global consistency check for charts and tables */
.dataTable, .chart-container canvas {
    width: 100% !important;
}

@media print {
    /* Set page margins */
    @page { margin: 1cm; }
    
    body { background: white !important; font-family: 'Inter', sans-serif !important; }
    .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
    
    /* Hide all UI elements except the table */
    .btn, .breadcrumb, header, footer, .navbar, .sidebar, 
    .input-group, .card-header, .Period-Selector, form, 
    .card-body .mb-3, .dropdown, th:last-child, td:last-child,
    .custom-stat-card, .btn-group, .badge.bg-success-soft {
        display: none !important;
    }

    .card { border: none !important; box-shadow: none !important; }
    .card-body { padding: 0 !important; }
    
    /* Table Styling for Print */
    .table { width: 100% !important; border: 1px solid #000 !important; border-collapse: collapse !important; }
    .table thead th { 
        background-color: #f0f0f0 !important; 
        color: #000 !important; 
        border: 1px solid #000 !important; 
        text-transform: uppercase !important;
        font-size: 10pt !important;
    }
    .table td { border: 1px solid #000 !important; font-size: 9pt !important; }
}
</style>

<?php
// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>