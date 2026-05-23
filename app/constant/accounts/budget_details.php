<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
autoEnforcePermission();

includeHeader();

// Check if user can approve/reject budgets (uses same permission as edit)
$can_approve = canEdit('budget');
$budget_id_for_action = null; // Will be set after fetching budget


// Get parameters
$category_id = $_GET['category_id'] ?? '';
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('n');

// Get budget details
$stmt = $pdo->prepare("
    SELECT b.*, 
           ec.name AS category_name,
           ec.description as category_description,
           u1.username as created_by_name,
           u2.username as approved_by_name
    FROM budgets b
    LEFT JOIN expense_categories ec ON b.category_id = ec.id
    LEFT JOIN users u1 ON b.created_by = u1.user_id
    LEFT JOIN users u2 ON b.approved_by = u2.user_id
    WHERE b.category_id = ? AND b.budget_year = ? AND b.budget_month = ?
");
$stmt->execute([$category_id, $year, $month]);
$budget = $stmt->fetch(PDO::FETCH_ASSOC);
$budget_id_for_action = $budget['budget_id'] ?? null;

if (!$budget) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Budget not found. <a href='" . getUrl('budget') . "'>Return to list</a></div></div>";
    exit();
}

// Get expenses for this budget
$expenses_stmt = $pdo->prepare("
    SELECT e.*, u.username as created_by_name, ba.account_name as bank_name
    FROM expenses e
    JOIN accounts a ON e.expense_account_id = a.account_id
    LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id
    LEFT JOIN users u ON e.created_by = u.user_id
    WHERE (a.category_id = ? OR a.account_name = ?) 
    AND YEAR(e.expense_date) = ? 
    AND MONTH(e.expense_date) = ?
    AND e.status IN ('pending', 'approved', 'paid')
    ORDER BY e.expense_date DESC
");
$expenses_stmt->execute([$category_id, $budget['category_name'], $year, $month]);
$expenses = $expenses_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_expenses = array_sum(array_column($expenses, 'amount'));
$remaining_budget = $budget['allocated_amount'] - $total_expenses;
$utilization_percentage = $budget['allocated_amount'] > 0 ? ($total_expenses / $budget['allocated_amount']) * 100 : 0;

// 1. Try to find a direct link (category_id)
$account_stmt = $pdo->prepare("SELECT account_id, account_name FROM accounts WHERE category_id = ? AND status = 'active' LIMIT 1");
$account_stmt->execute([$category_id]);
$associated_accounts = $account_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. If no direct link, try to find an exact name match
if (empty($associated_accounts)) {
    $name_stmt = $pdo->prepare("SELECT account_id, account_name FROM accounts WHERE account_name = ? AND status = 'active' LIMIT 1");
    $name_stmt->execute([$budget['category_name']]);
    $associated_accounts = $name_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$exact_match = !empty($associated_accounts);

// 3. Fallback: If no match found at all, fetch ALL expense accounts so the user can choose or create
if (!$exact_match) {
    $all_expense_accounts_stmt = $pdo->query("SELECT account_id, account_name FROM accounts WHERE status = 'active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%expense%') ORDER BY account_name ASC");
    $associated_accounts = $all_expense_accounts_stmt->fetchAll(PDO::FETCH_ASSOC);
    $using_fallback = true;
} else {
    $using_fallback = false;
}

// Fetch bank/cash accounts for the quick expense modal
$bank_accounts = $pdo->query("SELECT account_id, account_name FROM accounts WHERE status = 'active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%Asset%' OR type_name LIKE '%Bank%' OR type_name LIKE '%Cash%') ORDER BY account_name ASC")->fetchAll(PDO::FETCH_ASSOC);

global $company_name, $company_logo;
?>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">
        
        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Budget Performance Report</h2>
        <h5 class="text-muted"><?= htmlspecialchars($budget['category_name']) ?> (<?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?>)</h5>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-pie-chart"></i> Budget Details</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?= getUrl('budget') ?>">Budget Management</a></li>
                            <li class="breadcrumb-item active"><?= htmlspecialchars($budget['category_name']) ?></li>
                        </ol>
                    </nav>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary shadow-sm" onclick="printReport()">
                        <i class="bi bi-printer"></i> Print Report
                    </button>
                    <a href="<?= getUrl('budget') ?>?year=<?= $year ?>&month=<?= $month ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Budget
                    </a>
                    <?php if ($can_approve && $budget_id_for_action): ?>
                        <?php if ($budget['status'] !== 'approved' && $budget['status'] !== 'paid'): ?>
                        <button type="button" class="btn btn-success shadow-sm" onclick="approveBudget(<?= $budget_id_for_action ?>)">
                            <i class="bi bi-check-circle"></i> Approve
                        </button>
                        <?php endif; ?>
                        <?php if ($budget['status'] !== 'rejected' && $budget['status'] !== 'paid'): ?>
                        <button type="button" class="btn btn-warning shadow-sm" onclick="rejectBudget(<?= $budget_id_for_action ?>)">
                            <i class="bi bi-x-circle"></i> Reject
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Workflow Banner -->
    <?php
        $statusConfig = [
            'draft'    => ['bg' => 'secondary', 'icon' => 'bi-pencil-square', 'text' => 'This budget is a <strong>Draft</strong>. It needs to be reviewed and approved before it becomes active.'],
            'pending'  => ['bg' => 'warning',   'icon' => 'bi-hourglass-split', 'text' => 'This budget is <strong>Pending Approval</strong>. Awaiting review by an authorized manager.'],
            'approved' => ['bg' => 'success',   'icon' => 'bi-check-circle-fill', 'text' => 'This budget has been <strong>Approved</strong>. It is now active and expenses can be allocated against it.'],
            'rejected' => ['bg' => 'danger',    'icon' => 'bi-x-circle-fill', 'text' => 'This budget has been <strong>Rejected</strong>. Please revise and resubmit for review.'],
            'paid'     => ['bg' => 'primary',   'icon' => 'bi-cash-stack', 'text' => 'This budget has been <strong>Paid/Closed</strong>. No further changes can be made.'],
        ];
        $sc = $statusConfig[$budget['status']] ?? $statusConfig['draft'];
    ?>
    <div class="alert alert-<?= $sc['bg'] ?> d-flex align-items-center gap-2 d-print-none mb-4 shadow-sm" role="alert">
        <i class="bi <?= $sc['icon'] ?> fs-5"></i>
        <div><?= $sc['text'] ?></div>
    </div>

    <!-- Budget Overview Card -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Budget Overview</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <h6>Budget Name: <strong><?= htmlspecialchars($budget['category_name']) ?></strong></h6>
                    <p class="text-muted"><?= htmlspecialchars($budget['category_description'] ?? 'No description') ?></p>
                </div>
                <div class="col-md-4">
                    <h6>Period: <strong><?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?></strong></h6>
                    <p class="text-muted">Year: <?= $year ?>, Month: <?= $month ?></p>
                </div>
                <div class="col-md-4">
                    <h6>Status: 
                        <span class="badge bg-<?= get_status_badge($budget['status']) ?>">
                            <?= ucfirst($budget['status']) ?>
                        </span>
                    </h6>
                    <p class="text-muted">
                        Created by: <?= htmlspecialchars($budget['created_by_name']) ?><br>
                        <?php if ($budget['approved_by_name']): ?>
                        Approved by: <?= htmlspecialchars($budget['approved_by_name']) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            
            <hr>
            
            <!-- Budget Progress Bar -->
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <span>Budget Utilization</span>
                    <span><?= number_format($utilization_percentage, 1) ?>%</span>
                </div>
                <div class="progress" style="height: 20px;">
                    <div class="progress-bar 
                        <?= $utilization_percentage > 100 ? 'bg-danger' : 
                           ($utilization_percentage > 80 ? 'bg-warning' : 'bg-success') ?>" 
                        role="progressbar" 
                        style="width: <?= min($utilization_percentage, 100) ?>%">
                        <?= number_format($utilization_percentage, 1) ?>%
                    </div>
                </div>
                <div class="mt-1">
                    <small class="text-muted">
                        Spent: <?= format_currency($total_expenses) ?> | 
                        Allocated: <?= format_currency($budget['allocated_amount']) ?> | 
                        Remaining: <?= format_currency($remaining_budget) ?>
                    </small>
                </div>
            </div>

            <!-- Budget Plan Breakdown -->
            <?php
            $line_items = [];
            $is_service_budget = false;
            if (!empty($budget['line_items'])) {
                $parsed = json_decode($budget['line_items'], true);
                if (isset($parsed['is_service'])) {
                    // New wrapper format
                    $is_service_budget = (bool)$parsed['is_service'];
                    $line_items = $parsed['items'] ?? [];
                } else {
                    // Old plain-array format — treat as normal
                    $line_items = is_array($parsed) ? $parsed : [];
                }
            }
            if (!empty($line_items)):
            ?>
            <div class="mt-4 mb-3">
                <h6 class="fw-bold text-primary mb-3"><i class="bi bi-list-nested"></i> Planned Items Breakdown</h6>
                <div class="table-responsive border rounded bg-white shadow-sm">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead class="bg-light bg-opacity-75">
                            <tr>
                                <th class="ps-3" style="width: 45px;">#</th>
                                <th>Description</th>
                                <th class="text-center" style="width: 90px;">Units</th>
                                <th class="text-center" style="width: 70px;">Qty</th>
                                <th class="text-end" style="width: 120px;">Price (TSh)</th>
                                <th class="text-end" style="width: 75px;">Tax %</th>
                                <th class="text-end pe-3" style="width: 130px;">Total (TSh)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $i = 1;
                            foreach ($line_items as $item):
                                $tax_rate = floatval($item['tax_rate'] ?? 0);
                                $sub_total = ($item['qty'] ?? 1) * ($item['price'] ?? 0) * (1 + $tax_rate / 100);
                            ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $i++ ?></td>
                                <td class="fw-medium text-wrap" style="min-width: 200px;"><?= htmlspecialchars($item['desc'] ?? '---') ?></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?= htmlspecialchars($item['units'] ?? '—') ?></span></td>
                                <td class="text-center fw-bold"><?= number_format($item['qty'] ?? 0, 2) ?></td>
                                <td class="text-end"><?= number_format($item['price'] ?? 0, 2) ?></td>
                                <td class="text-end"><?= $tax_rate > 0 ? number_format($tax_rate, 2) . '%' : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end pe-3 fw-bold text-primary"><?= number_format($sub_total, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="6" class="ps-3">Grand Total Planned Amount</td>
                                <td class="text-end pe-3 text-primary"><?= format_currency($budget['allocated_amount']) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Budget Notes -->
            <?php if (!empty($budget['notes'])): ?>
            <div class="mt-3">
                <h6>Notes:</h6>
                <div class="alert alert-info">
                    <?= nl2br(htmlspecialchars($budget['notes'])) ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Budget Attachment -->
            <div class="mt-3">
                <h6>Attachment:</h6>
                <?php if (!empty($budget['attachment'])): ?>
                <div class="d-flex align-items-center gap-3 p-3 border rounded bg-light">
                    <i class="bi bi-paperclip fs-4 text-primary"></i>
                    <div class="flex-grow-1">
                        <div class="fw-bold small"><?= htmlspecialchars(basename($budget['attachment'])) ?></div>
                        <small class="text-muted">Attached document</small>
                    </div>
                    <a href="<?= getUrl('') . '/' . htmlspecialchars($budget['attachment']) ?>" target="_blank" class="btn btn-sm btn-primary">
                        <i class="bi bi-download me-1"></i> Download
                    </a>
                </div>
                <?php else: ?>
                <div class="p-3 border rounded bg-light text-muted"><i class="bi bi-paperclip me-2"></i>No document attached to this budget.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Expenses for this Period</h5>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge bg-light text-dark">
                        <?= count($expenses) ?> expenses
                    </span>
                    <button type="button" class="btn btn-primary btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#quickExpenseModal">
                        <i class="bi bi-plus-circle"></i> Quick Add
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (count($expenses) > 0): ?>
                <div class="table-responsive border-0 w-100">
                    <table id="expensesTable" class="table table-hover align-middle nowrap w-100" style="width:100%">
                        <thead class="bg-light">
                            <tr>
                                <th style="width: 20px;"></th> <!-- Control Column -->
                                <th style="width: 30px;">S/NO</th>
                                <th>Date</th>
                                <th>Vendor & Description</th>
                                <th class="text-end">Amount</th>
                                <th>Bank/Cash</th>
                                <th>Ref #</th>
                                <th>Status</th>
                                <th>Created By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sn = 1; foreach ($expenses as $expense): ?>
                            <tr>
                                <td></td> <!-- Control cell -->
                                <td class="text-center text-muted small fw-bold"><?= $sn++ ?></td>
                                <td><?= date('M d, Y', strtotime($expense['expense_date'])) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($expense['vendor'] ?? 'N/A') ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($expense['description']) ?></small>
                                </td>
                                <td class="text-end"><strong><?= format_currency($expense['amount']) ?></strong></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($expense['bank_name'] ?? 'N/A') ?></span></td>
                                <td><code class="small"><?= htmlspecialchars($expense['reference_number'] ?? 'N/A') ?></code></td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($expense['status']) ?>">
                                        <?= ucfirst($expense['status']) ?>
                                    </span>
                                </td>
                                <td><small><?= htmlspecialchars($expense['created_by_name']) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-dark">
                                <td colspan="4"><strong>Total Expenses</strong></td>
                                <td class="text-end"><strong><?= format_currency($total_expenses) ?></strong></td>
                                <td colspan="4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-receipt-cutoff" style="font-size: 4rem; color: #dee2e6;"></i>
                    <h5 class="mt-3 text-muted fw-bold">No Expenses Recorded</h5>
                    <p class="text-muted">No expenses have been recorded for this category and period yet.</p>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#quickExpenseModal">
                        <i class="bi bi-plus-circle"></i> Add First Expense
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Expense Modal -->
<div class="modal fade" id="quickExpenseModal" tabindex="-1" aria-labelledby="quickExpenseModalLabel">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="quickExpenseModalLabel"><i class="bi bi-lightning-fill"></i> Quick Expense</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quickExpenseForm">
                <input type="hidden" name="expense_date" value="<?= date('Y-m-d', mktime(0, 0, 0, $month, 1, $year)) ?>">
                <input type="hidden" name="status" value="paid">
                
                <div class="modal-body p-4">
                    <div id="modal-message"></div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Budget Category</label>
                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($budget['category_name']) ?>" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold mb-1">Expense Account <span class="text-danger">*</span></label>
                        <div class="input-group shadow-sm mb-2">
                            <select class="form-select <?= $exact_match ? '' : 'border-end-0' ?>" name="expense_account_id" id="accountSelect" required>
                                <option value="">Select Expense Account...</option>
                                <?php foreach ($associated_accounts as $acc): ?>
                                    <option value="<?= $acc['account_id'] ?>" <?= $exact_match ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($acc['account_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$exact_match): ?>
                            <button class="btn btn-outline-primary border-start-0" type="button" onclick="toggleNewAccountForm()" title="Create New Account">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <!-- New Account Inline Form (Visible by default if no match) -->
                        <div id="newAccountForm" class="p-3 bg-light border rounded-3 mb-3 shadow-sm border-primary border-opacity-25" style="<?= $using_fallback ? 'display: block;' : 'display: none;' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="small fw-bold mb-0 text-primary"><i class="bi bi-plus-circle-fill"></i> New Expense Account</h6>
                                <button type="button" class="btn-close smallest" onclick="toggleNewAccountForm()" style="font-size: 0.6rem;"></button>
                            </div>
                            <div class="row g-2">
                                <div class="col-7">
                                    <label class="smallest fw-600 opacity-75">Account Name</label>
                                    <input type="text" id="new_acc_name" class="form-control form-control-sm" value="<?= htmlspecialchars($budget['category_name']) ?>">
                                </div>
                                <div class="col-5">
                                    <label class="smallest fw-600 opacity-75">Code</label>
                                    <input type="text" id="new_acc_code" class="form-control form-control-sm" placeholder="EXP-<?= rand(100, 999) ?>">
                                </div>
                                <div class="col-12 mt-2">
                                    <button type="button" class="btn btn-primary btn-sm w-100" onclick="saveQuickAccount()">
                                        <i class="bi bi-check2"></i> Create & Link Account
                                    </button>
                                </div>
                            </div>
                        </div>

                        <?php if ($using_fallback): ?>
                            <div class="form-text text-warning small px-1">
                                <i class="bi bi-exclamation-triangle"></i> No exact matching account found. Please select manually.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Bank/Cash Account <span class="text-danger">*</span></label>
                        <select class="form-select" name="bank_account_id" required>
                            <option value="">Select Account...</option>
                            <?php foreach ($bank_accounts as $acc): ?>
                                <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">TSh</span>
                            <input type="number" class="form-control" name="amount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Description <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="description" required placeholder="Description of the expense">
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-save"></i> Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable for Expenses
    let expensesTable;
    if ($('#expensesTable').length) {
        expensesTable = $('#expensesTable').DataTable({
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search records...",
                lengthMenu: "Show _MENU_",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            },
            responsive: {
                details: {
                    type: 'column',
                    target: 0 // Target the first empty column for the icon
                }
            },
            columnDefs: [
                { className: 'dtr-control', orderable: false, targets: 0 },
                { responsivePriority: 1, targets: 1 }, // S/NO
                { responsivePriority: 2, targets: 2 }, // Date
                { responsivePriority: 10, targets: 3 }, // Vendor/Desc
                { responsivePriority: 11, targets: 4 }, // Amount
                { responsivePriority: 20, targets: 7 }, // Status
                { className: "text-end", targets: 4 }
            ],
            order: [[2, 'desc']], // Sort by Date
            pageLength: 50,
            dom: 'rtip',
            drawCallback: function() {
                this.api().responsive.recalc();
            }
        });

        // Ensure table fills the width on both web and mobile
        setTimeout(() => { 
            if (expensesTable) {
                expensesTable.columns.adjust().responsive.recalc();
                expensesTable.draw();
            }
        }, 150);
        
        $(window).on('resize', function() {
            if (expensesTable) expensesTable.columns.adjust().responsive.recalc();
        });
    }

    // Log page view
    logReportAction('Viewed Budget Details', 'User viewed budget performance for <?= addslashes($budget['category_name']) ?> (<?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?>)');

    window.printReport = function() {
        logReportAction('Printed Budget Report', 'User generated a printed budget performance report for <?= addslashes($budget['category_name']) ?>');
        window.print();
    };

    $('#quickExpenseForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]');
        const selectedAccount = $('#accountSelect').val();
        const isNewAccountVisible = $('#newAccountForm').is(':visible');
        
        // Check if we need to create an account first
        if (!selectedAccount && isNewAccountVisible) {
            const name = $('#new_acc_name').val();
            const code = $('#new_acc_code').val();
            
            if (!name || !code) {
                alert('Please provide an account name and code.');
                return;
            }
            
            // Auto-create account first
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Creating Account...');
            
            $.ajax({
                url: '/api/account/save_account.php',
                type: 'POST',
                data: {
                    account_name: name,
                    account_code: code,
                    account_type: 'Expense',
                    category_id: '<?= $category_id ?>',
                    status: 'active'
                },
                success: function(response) {
                    if (response.success) {
                        // Success! Now use the new account ID to save the expense
                        const newId = response.account_id; // Assume API returns account_id
                        saveExpenseWithAccount(newId);
                    } else {
                        alert('Error creating account: ' + response.message);
                        $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Save Expense');
                    }
                },
                error: function() {
                    alert('Server error while creating account.');
                    $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Save Expense');
                }
            });
        } else if (!selectedAccount) {
            alert('Please select an expense account.');
        } else {
            saveExpenseWithAccount(selectedAccount);
        }
    });

    function saveExpenseWithAccount(accountId) {
        const $btn = $('#quickExpenseForm button[type="submit"]');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving Expense...');
        
        const formData = $('#quickExpenseForm').serializeArray();
        // Update or add expense_account_id
        let found = false;
        for (let i = 0; i < formData.length; i++) {
            if (formData[i].name === 'expense_account_id') {
                formData[i].value = accountId;
                found = true;
                break;
            }
        }
        if (!found) {
            formData.push({name: 'expense_account_id', value: accountId});
        }

        $.ajax({
            url: '/api/account/add_expense.php',
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Expense added and budget updated.',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        logReportAction('Added Quick Expense', 'User added an expense of ' + formData.find(f => f.name === 'amount').value + ' for budget category <?= addslashes($budget['category_name']) ?>');
                        location.reload();
                    });
                } else {
                    $('#modal-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Save Expense');
                }
            },
            error: function() {
                $('#modal-message').html('<div class="alert alert-danger">Connect Error. Please check server.</div>');
                $btn.prop('disabled', false).html('<i class="bi bi-save"></i> Save Expense');
            }
        });
    }
});

function toggleNewAccountForm() {
    const $form = $('#newAccountForm');
    const $select = $('#accountSelect');
    if ($form.is(':visible')) {
        $form.slideUp();
        $select.prop('disabled', false);
    } else {
        $form.slideDown();
        $select.prop('disabled', true);
    }
}

function saveQuickAccount() {
    const name = $('#new_acc_name').val();
    const code = $('#new_acc_code').val();
    const categoryId = '<?= $category_id ?>';

    if (!name || !code) {
        alert('Please enter both name and code for the new account.');
        return;
    }

    const $btn = $('#newAccountForm .btn-primary');
    const oldText = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Creating...');

    $.ajax({
        url: '/api/account/save_account.php',
        type: 'POST',
        data: {
            account_name: name,
            account_code: code,
            account_type: 'Expense',
            category_id: categoryId,
            status: 'active'
        },
        success: function(response) {
            if (response.success) {
                // Add to select and select it
                // We'll reload the page part or just add option
                // For simplicity and correctness with all state, we reload or fetch accounts.
                // Let's just add it to select and close form.
                // Since we don't have the new ID easily without response update, let's reload or just use the name to find it?
                // Most APIs return the ID. Let's assume response.data.account_id or similar?
                // Based on common patterns.
                
                showToast('success', 'Account created! Reloading to sync...');
                logReportAction('Created Quick Account', 'User created new expense account ' + name + ' from budget view');
                setTimeout(() => location.reload(), 1000);
            } else {
                alert('Error: ' + response.message);
                $btn.prop('disabled', false).html(oldText);
            }
        },
        error: function() {
            alert('Server error while creating account.');
            $btn.prop('disabled', false).html(oldText);
        }
    });
}

function showToast(type, msg) {
    Swal.fire({
        icon: type,
        title: msg,
        timer: 2000,
        showConfirmButton: false
    });
}

function approveBudget(budgetId) {
    Swal.fire({
        title: 'Approve Budget?',
        text: 'This budget will be marked as Approved and become active.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-circle"></i> Yes, Approve',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/update_budget_status.php', { budget_id: budgetId, status: 'approved' }, function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Budget Approved!',
                        text: 'The budget has been approved successfully.',
                        timer: 1800,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message || 'Failed to approve budget', 'error');
                }
            }, 'json');
        }
    });
}

function rejectBudget(budgetId) {
    Swal.fire({
        title: 'Reject Budget?',
        text: 'This budget will be marked as Rejected.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-x-circle"></i> Yes, Reject',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/update_budget_status.php', { budget_id: budgetId, status: 'rejected' }, function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Budget Rejected',
                        text: 'The budget has been rejected.',
                        timer: 1800,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message || 'Failed to reject budget', 'error');
                }
            }, 'json');
        }
    });
}
</script>

<style>
.progress {
    border-radius: 10px;
    overflow: hidden;
}
.progress-bar {
    transition: width 0.6s ease;
}
.table tfoot td {
    font-size: 1.1rem;
}
.smallest {
    font-size: 0.75rem;
}
.fw-600 {
    font-weight: 600;
}

    @page { margin: 10mm 8mm 16mm 8mm; }
    @media print {
        body {
            background: white !important; 
            -webkit-print-color-adjust: exact; 
            margin: 0 !important; 
            padding: 0 !important; 
            width: 100% !important;
        }
        .container, .container-fluid { 
            max-width: 100% !important; 
            width: 100% !important; 
            padding: 0 !important; 
            margin: 0 !important; 
            display: block !important;
        }
        .btn, .nav-tabs, .d-flex.gap-2, .d-print-none, .breadcrumb, header, footer, .sidebar, .modal, .alert-secondary, .alert-warning, .alert-danger, .alert-primary {
            display: none !important;
        }
        .card {
            border: 1px solid #dee2e6 !important;
            box-shadow: none !important;
            margin-bottom: 20px !important;
            width: 100% !important;
            display: block !important;
        }
        .card-header {
            background-color: #f8f9fa !important;
            border-bottom: 2px solid #333 !important;
            padding: 10px !important;
            color: black !important;
            display: block !important;
        }
        .card-body {
            padding: 15px !important;
            display: block !important;
            width: 100% !important;
        }
        .row {
            display: flex !important;
            flex-wrap: wrap !important;
            width: 100% !important;
            margin: 0 !important;
        }
        .col-md-4 {
            flex: 0 0 33.333333% !important;
            max-width: 33.333333% !important;
        }
        .progress { 
            border: 1px solid #ddd !important; 
            height: 25px !important; 
            width: 100% !important; 
            display: flex !important;
        }
        .table { 
            width: 100% !important; 
            border: 1px solid #dee2e6 !important; 
            border-collapse: collapse !important;
        }
        .table th, .table td {
            border: 1px solid #dee2e6 !important;
            padding: 8px !important;
        }
        .alert-info { border: 1px solid #bee5eb !important; background: #f8f9fa !important; color: #31708f !important; }
        .text-success { color: #198754 !important; }
        .text-primary { color: #0d6efd !important; }
    }
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</style>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
<?php includeFooter(); ?>