<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
// Phase 5b — supply explicit page-key; argless call was ineffective.
autoEnforcePermission('budget');

includeHeader();

// Check if user can approve/reject budgets (uses same permission as edit)
$can_approve = canEdit('budget');
$budget_id_for_action = null; // Will be set after fetching budget


// Get parameters
$category_id = $_GET['category_id'] ?? '';
$year  = !empty($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = !empty($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// Get budget details
$stmt = $pdo->prepare("
    SELECT b.*, 
           ec.name AS category_name,
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

// Phase C — block viewing budgets on projects not in user scope (HTML-safe)
if (!empty($budget['project_id']) && !userCan('project', (int)$budget['project_id'])) {
    http_response_code(403);
    die('Access denied: this budget belongs to a project not in your scope.');
}

// Get expenses for this budget:
// Match via (1) account's category_id, (2) account name = category name,
// OR (3) expense_category_map link — so Quick Expense always appears regardless
// of which GL account the user picked.
$expenses_stmt = $pdo->prepare("
    SELECT e.*, u.username as created_by_name, ba.account_name as bank_name
    FROM expenses e
    JOIN accounts a ON e.expense_account_id = a.account_id
    LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id
    LEFT JOIN users u ON e.created_by = u.user_id
    WHERE (
        a.category_id = ?
        OR a.account_name = ?
        OR e.expense_id IN (
            SELECT ecm.expense_id FROM expense_category_map ecm WHERE ecm.category_id = ?
        )
    )
    AND YEAR(e.expense_date) = ?
    AND MONTH(e.expense_date) = ?
    AND e.status IN ('pending', 'approved', 'paid')
    ORDER BY e.expense_date DESC
");
$expenses_stmt->execute([$category_id, $budget['category_name'], $category_id, $year, $month]);
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

// Fetch bank/cash accounts for the quick expense modal using the canonical helper
// (same source as every other "Paid From" dropdown in the app)
require_once __DIR__ . '/../../../core/payment_source.php';
$bank_accounts = cashBankAccounts($pdo);

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
                    <?php
                    $enable_projects = 0;
                    try {
                        $stmt_ep = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
                        $stmt_ep->execute();
                        $enable_projects = $stmt_ep->fetchColumn() ?: 0;
                    } catch (Exception $e) {}
                    if ($enable_projects && !empty($budget['project_id'])): ?>
                    <a href="<?= getUrl('project_view') ?>?id=<?= $budget['project_id'] ?>" class="btn btn-outline-primary">
                        <i class="bi bi-kanban"></i> Back to Project
                    </a>
                    <?php endif; ?>
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
    <div class="card mb-4 print-flow-card">
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

    <div class="card print-flow-card">
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
                            <tr class="table-light">
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
                <input type="hidden" name="category_id" value="<?= (int)$category_id ?>">
                <input type="hidden" name="budget_id" value="<?= (int)($budget['budget_id'] ?? 0) ?>"><?php // links expense to this budget and populates expense_category_map ?>
                
                <div class="modal-body p-4">
                    <div id="modal-message"></div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Budget Category</label>
                        <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($budget['category_name']) ?>" readonly>
                    </div>

                    <!-- Budget status panel — live-updated as user types amount -->
                    <?php
                        $qe_pct   = (float)$utilization_percentage;
                        $qe_bar   = $qe_pct >= 100 ? 'bg-danger' : ($qe_pct >= 80 ? 'bg-warning' : 'bg-success');
                        $qe_badge = $qe_pct >= 100 ? 'bg-danger' : ($qe_pct >= 80 ? 'bg-warning text-dark' : ($qe_pct > 0 ? 'bg-info text-dark' : 'bg-secondary'));
                        $qe_label = $qe_pct >= 100 ? 'Fully Used' : ($qe_pct >= 80 ? 'Nearly Full' : ($qe_pct > 0 ? 'Partially Used' : 'Unused'));
                    ?>
                    <div class="mb-3 p-3 rounded border <?= $qe_pct >= 100 ? 'border-danger bg-danger bg-opacity-10' : 'bg-light' ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-muted">Budget Utilisation</span>
                            <span class="badge <?= $qe_badge ?>"><?= $qe_label ?> — <?= number_format($qe_pct, 1) ?>%</span>
                        </div>
                        <div class="progress mb-2" style="height:6px">
                            <div class="progress-bar <?= $qe_bar ?>" id="qeBudgetBar"
                                 style="width:<?= min($qe_pct, 100) ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Used: <strong>TSh <?= number_format((float)$total_expenses, 0) ?></strong></span>
                            <span class="text-muted">Remaining: <strong id="qeRemaining" class="<?= (float)$remaining_budget <= 0 ? 'text-danger' : 'text-success' ?>">TSh <?= number_format((float)$remaining_budget, 0) ?></strong></span>
                        </div>
                        <div id="qeAfterThis" class="small mt-1" style="display:none"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold mb-1">Expense Account <span class="text-danger">*</span></label>
                        <select name="expense_account_id" id="quickExpenseAccountSelect" style="width:100%" required>
                            <option value="">Type to search expense account…</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Paid From (Cash/Bank) <span class="text-danger">*</span></label>
                        <select name="bank_account_id" id="quickBankAccountSelect" style="width:100%" required>
                            <option value="">Select cash/bank account…</option>
                            <?php foreach ($bank_accounts as $acc): ?>
                                <option value="<?= $acc['account_id'] ?>">
                                    <?= htmlspecialchars(($acc['account_code'] ? $acc['account_code'] . ' — ' : '') . $acc['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">TSh</span>
                            <input type="number" class="form-control" name="amount" id="qeAmount" step="0.01" min="0" required placeholder="0.00">
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

    // Init Select2 on both pickers when the modal opens
    $('#quickExpenseModal').on('shown.bs.modal', function() {
        if (!$('#quickExpenseAccountSelect').hasClass('select2-hidden-accessible')) {
            $('#quickExpenseAccountSelect').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#quickExpenseModal'),
                width: '100%',
                placeholder: 'Type to search expense account…',
                allowClear: true,
                minimumInputLength: 1,
                ajax: {
                    url: '<?= buildUrl('api/search_accounts') ?>',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) { return { q: params.term, type: 'expense' }; },
                    processResults: function(data) { return { results: data.results }; },
                    cache: true
                }
            });
        }
        if (!$('#quickBankAccountSelect').hasClass('select2-hidden-accessible')) {
            $('#quickBankAccountSelect').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#quickExpenseModal'),
                width: '100%',
                placeholder: 'Select cash/bank account…',
                allowClear: true
            });
        }
    });

    // Reset selects when modal closes
    $('#quickExpenseModal').on('hidden.bs.modal', function() {
        $('#modal-message').html('');
        $('#quickExpenseAccountSelect').val(null).trigger('change');
        $('#quickBankAccountSelect').val(null).trigger('change');
        $('#quickExpenseForm')[0].reset();
        $('#qeAfterThis').hide().html('');
    });

    // Budget constants from PHP (at page render time — reload after saving to get fresh values)
    const QE_BUDGET_REMAINING = <?= number_format((float)$remaining_budget, 2, '.', '') ?>;
    const QE_BUDGET_ALLOCATED = <?= number_format((float)$budget['allocated_amount'], 2, '.', '') ?>;
    const QE_BUDGET_USED      = <?= number_format((float)$total_expenses, 2, '.', '') ?>;
    const qeFmt = v => 'TSh ' + Math.abs(v).toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    // Live indicator: update "remaining after this expense" as the user types
    $(document).on('input', '#qeAmount', function() {
        const amt = parseFloat($(this).val()) || 0;
        const $el = $('#qeAfterThis');
        if (amt <= 0) { $el.hide().html(''); return; }
        const after = QE_BUDGET_REMAINING - amt;
        if (after < 0) {
            $el.show().html('<span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> EXCEEDS budget by ' + qeFmt(-after) + ' — save will be blocked.</span>');
        } else {
            $el.show().html('<span class="text-success"><i class="bi bi-check-circle-fill"></i> After this: <strong>' + qeFmt(after) + '</strong> remaining</span>');
        }
    });

    $('#quickExpenseForm').on('submit', function(e) {
        e.preventDefault();
        if (!$('#quickExpenseAccountSelect').val()) {
            $('#modal-message').html('<div class="alert alert-danger">Please select an expense account.</div>');
            return;
        }
        if (!$('#quickBankAccountSelect').val()) {
            $('#modal-message').html('<div class="alert alert-danger">Please select a cash/bank account.</div>');
            return;
        }

        // Budget enforcement — hard block before any AJAX call
        const qeAmt = parseFloat($('#qeAmount').val()) || 0;
        if (qeAmt > QE_BUDGET_REMAINING) {
            Swal.fire({
                icon: 'error',
                title: 'Cannot Exceed Budget',
                html: '<p class="mb-3">This expense of <strong>' + qeFmt(qeAmt) + '</strong> would push spending past the approved budget limit.</p>'
                    + '<table class="table table-sm table-bordered text-start mb-0">'
                    + '<tr><td class="text-muted">Budget Allocated</td><td class="text-end fw-bold">' + qeFmt(QE_BUDGET_ALLOCATED) + '</td></tr>'
                    + '<tr><td class="text-muted">Already Used</td><td class="text-end fw-bold text-warning">' + qeFmt(QE_BUDGET_USED) + '</td></tr>'
                    + '<tr class="table-danger"><td class="fw-bold">Remaining (Maximum)</td><td class="text-end fw-bold text-danger">' + qeFmt(QE_BUDGET_REMAINING) + '</td></tr>'
                    + '</table>',
                confirmButtonText: 'OK, I will adjust the amount',
                confirmButtonColor: '#dc3545'
            });
            return;
        }
        const $btn = $(this).find('button[type="submit"]');
        const orig = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving…');
        $.ajax({
            url: '<?= buildUrl('api/account/add_expense.php') ?>',
            type: 'POST',
            data: $(this).serializeArray(),
            dataType: 'json',
            success: function(res) {
                $btn.prop('disabled', false).html(orig);
                if (res.success) {
                    let html = '<p class="mb-2">Expense <strong>#' + res.id + '</strong> has been saved.</p>';
                    if (res.posted && res.ledger) {
                        html += '<table class="table table-sm table-bordered text-start mt-2 mb-0">'
                              + '<thead class="table-light"><tr><th>Side</th><th>Account</th><th class="text-end">Amount (TSh)</th></tr></thead>'
                              + '<tbody>'
                              + '<tr><td><span class="badge bg-danger">Dr</span></td><td>' + res.ledger.dr + '</td><td class="text-end fw-bold">' + res.ledger.amount + '</td></tr>'
                              + '<tr><td><span class="badge bg-success">Cr</span></td><td>' + res.ledger.cr + '</td><td class="text-end fw-bold">' + res.ledger.amount + '</td></tr>'
                              + '</tbody></table>'
                              + '<p class="text-muted small mt-2 mb-0">Both sides of the ledger are updated. The expense appears in the P&amp;L and cash is reduced on the Balance Sheet.</p>';
                    } else {
                        html += '<p class="text-muted small mb-0">Saved as <strong>Pending</strong> — no ledger entry yet. It will post when approved and marked paid.</p>';
                    }
                    Swal.fire({
                        icon: 'success',
                        title: res.posted ? 'Expense Posted to Ledger' : 'Expense Saved',
                        html: html,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#0d6efd',
                        allowOutsideClick: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Could Not Save Expense',
                        text: res.message || 'An unexpected error occurred.',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#dc3545'
                    });
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html(orig);
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Server error — please try again.';
                Swal.fire({
                    icon: 'error',
                    title: 'Server Error',
                    text: msg,
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#dc3545'
                });
            }
        });
    });
});

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

        /* Same fix as products.php: the shared responsive.css rule
           `.card { page-break-inside: avoid }` applies to every .card on
           every printed page (deliberately left untouched globally — most
           print pages depend on it). If the Expenses table here grows long,
           "never break inside this card" would push the whole card to the
           next page instead of letting it flow, same bug as products.php
           had. Scoped to just these two cards via .print-flow-card so no
           other page's cards are affected. */
        .print-flow-card {
            page-break-inside: auto !important;
            break-inside: auto !important;
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

        /* bms-mobile-cards.js auto-injects a mobile card view under every
           DataTable that doesn't already have one (including #expensesTable
           above), wrapped in a `.row` for its own grid layout. responsive.css
           already force-hides it globally on print via `.bms-auto-cards`,
           but the generic `.row { display: flex !important; }` rule right
           above this — meant only for the Budget Overview info grid — ties
           on specificity and wins on source order, silently un-hiding it and
           printing the card view under the table. ID selector always wins
           regardless of order, so this reliably keeps print to table-only. */
        #expensesTable-bms-cards,
        .bms-auto-cards {
            display: none !important;
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
</style>

<?php includeFooter(); ?>