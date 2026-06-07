<?php
// scope-audit: skip — expense list + stats loaded via AJAX from api/account/get_expenses.php scoped with scopeFilterSqlNullable; project dropdown filtered below
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';

// Include the header
// Phase 5b — supply explicit page-key; argless call was ineffective.
autoEnforcePermission('expenses');

includeHeader();

// Fetch Expense Accounts (canonical: active expense + finance_cost)
$expense_accounts = expenseAccounts($pdo);

// Bank/Cash (Paid-From) accounts — use the one canonical, consistent filter
// shared by every payment form (active cash/bank asset accounts).
$bank_accounts = cashBankAccounts($pdo);

// Fetch Projects if enabled — filtered to user's assigned projects for non-admins
$enable_projects = get_setting('enable_projects');
$projects = [];
if ($enable_projects == '1') {
    if (isAdmin()) {
        $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $assigned = array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? []));
        if (!empty($assigned)) {
            $ph = implode(',', array_fill(0, count($assigned), '?'));
            $pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status = 'active' AND project_id IN ($ph) ORDER BY project_name ASC");
            $pstmt->execute($assigned);
            $projects = $pstmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Fetch Suppliers, Staff and Sub-Contractors for "Paid To"
$suppliers       = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$employees       = $pdo->query("SELECT employee_id, first_name, last_name FROM employees WHERE status = 'active' ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$sub_contractors = $pdo->query("SELECT supplier_id, supplier_name FROM sub_contractors WHERE status = 'active' ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch company logo and settings for print
$c_logo = getSetting('company_logo', '');
$c_name = getSetting('company_name', 'BMS');

// Build absolute logo HTML for use in JS print windows
$_proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_logo_url = !empty($c_logo) ? $_proto . '://' . $_host . '/' . ltrim($c_logo, '/') : '';
$_pv_logo_html = !empty($_logo_url)
    ? '<img src="' . htmlspecialchars($_logo_url) . '" alt="' . htmlspecialchars($c_name) . '" style="max-height:70px; width:auto; display:block; margin-bottom:4px;">'
    : '';
$_pv_logo_js = addslashes($_pv_logo_html); // JS-safe version
?>

<div class="container-fluid py-4 px-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= htmlspecialchars($c_name) ?></h1>
        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Expense Management Report</h2>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="card shadow-sm border-0 bg-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-1 fw-bold text-primary"><i class="bi bi-cash-coin"></i> Expenses Management</h2>
                            <p class="mb-0 text-muted">Track and manage all expenses</p>
                        </div>
                        <div>
                            <?php if (canCreate('expenses')): ?>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                                <i class="bi bi-plus-circle"></i> Add New Expense
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
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

    <!-- Summary Cards -->
    <div class="row mb-4" id="print-stats-cards">
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3 d-none d-sm-flex">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">TOTAL EXPENSES</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-total-expenses" style="font-size: 1.1rem;">0.00</h4>
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
                            <i class="bi bi-calendar-month"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">THIS MONTH</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-month-total" style="font-size: 1.1rem;">0.00</h4>
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
                            <i class="bi bi-calendar-event"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">THIS YEAR</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-year-total" style="font-size: 1.1rem;">0.00</h4>
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
                            <i class="bi bi-receipt"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">RECORDS</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-total-records" style="font-size: 1.1rem;">0</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Expense Account</label>
                    <select class="form-select select2-static" id="categoryFilter" style="width: 100%;">
                        <option value="">All Accounts</option>
                        <?php foreach ($expense_accounts as $acc): ?>
                            <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="dateFromFilter">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="dateToFilter">
                </div>
                <div class="col-md-12 d-flex justify-content-end">
                    <button type="button" class="btn btn-primary me-2" onclick="applyFilters()">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex align-items-center gap-2 mb-4 flex-nowrap overflow-x-auto pb-2 pb-md-0" style="scrollbar-width: none; -ms-overflow-style: none;">
        <style>
            .compact-action-bar::-webkit-scrollbar { display: none; }
            .btn-action-compact {
                background: #fff !important;
                color: #444 !important;
                border: 1px solid #dee2e6 !important;
                padding: 0.4rem 0.6rem !important;
                font-size: 0.75rem !important;
                height: 32px !important;
                display: flex !important;
                align-items: center !important;
                white-space: nowrap !important;
                box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
                min-width: 60px;
                justify-content: center;
            }
            .btn-action-compact:hover { background: #f8f9fa !important; }
            .action-label-compact {
                font-size: 0.7rem !important;
                color: #6c757d !important;
                white-space: nowrap !important;
            }
            .search-input-compact {
                height: 32px !important;
                font-size: 0.8rem !important;
                min-width: 150px !important;
            }
        </style>
        
        <div class="d-flex align-items-center gap-1 flex-nowrap">
            <button type="button" class="btn btn-action-compact" onclick="copyTable()">
                <i class="bi bi-clipboard text-info me-1"></i> Copy
            </button>
            <button type="button" class="btn btn-action-compact" onclick="exportExpenses()">
                <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> CSV
            </button>
            <button type="button" class="btn btn-action-compact" onclick="printTable()">
                <i class="bi bi-printer text-primary me-1"></i> Print
            </button>
        </div>

        <div class="d-flex align-items-center bg-white px-2 rounded border shadow-sm" style="height: 32px;">
            <span class="action-label-compact me-2">Show:</span>
            <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 45px; font-size: 0.8rem; background: transparent; box-shadow: none;" onchange="$('#expensesTable').DataTable().page.len(this.value).draw();">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="-1">All</option>
            </select>
        </div>

        <div class="input-group input-group-sm shadow-sm" style="max-width: 250px;">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted" style="font-size: 0.8rem;"></i></span>
            <input type="text" class="form-control border-start-0 search-input-compact" placeholder="Search..." onkeyup="$('#expensesTable').DataTable().search(this.value).draw();">
        </div>

        <div class="ms-auto d-none d-lg-block">
            <span class="badge bg-success-soft text-success border border-success px-2 py-1 rounded-pill" id="stat-total-records-badge" style="font-size: 0.7rem;">
                <i class="bi bi-check-circle-fill me-1"></i> 0 records
            </span>
        </div>
    </div>

    <!-- Expenses Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold">Expense Records</h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <div class="table-responsive">
                <table id="expensesTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th style="width:70px;">S/NO</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <?php if ($enable_projects == '1'): ?>
                            <th>Project</th>
                            <?php endif; ?>
                            <th>Amount</th>
                            <th>Paid To</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Mobile Card View (auto-rendered on screens <= 768px) -->
<div id="mobile-expense-cards" class="px-2" style="display:none;"></div>

<!-- Add/Edit Expense Modal -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-bs-focus="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addExpenseModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Expense
                </h5>
                <button type="button" class="btn-close btn-close-white" aria-label="Close" onclick="closeAddExpenseModal()"></button>
            </div>
            <form id="addExpenseForm">
                <div class="modal-body p-4">
                    <div id="add-expense-message" class="mb-3"></div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Expense Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center">
                                <label class="form-label small fw-bold mb-0">Expense Type <span class="text-danger">*</span></label>
                                <a href="<?= getUrl('expense_types') ?>" target="_blank" class="small text-decoration-none" title="Open the Expense Types & Categories page in a new tab">
                                    <i class="bi bi-gear-wide-connected me-1"></i>Manage types &amp; categories
                                </a>
                            </div>
                            <select class="form-select expense-type-sel mt-1" name="expense_type" id="ex_type_id" required>
                                <option value="">Select Type</option>
                            </select>
                        </div>

                        <div class="col-md-12 add-expense-category-block" style="display:none;">
                            <label class="form-label small fw-bold text-primary">
                                <i class="bi bi-tags-fill me-1"></i> Expense Category <span class="text-danger">*</span>
                            </label>
                            <div id="category_cascade_container">
                                <!-- Cascade dropdowns injected here -->
                            </div>
                            <input type="hidden" name="category_id" id="selected_category_id" value="">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Paid to</label>
                            <select class="form-select select2-static" name="paid_to_type" id="paid_to_type">
                                <option value="">Select Type</option>
                                <option value="supplier">Supplier</option>
                                <option value="staff">Staff (Employee)</option>
                                <option value="sub_contractor">Sub Contractor</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-none" id="paid_to_id_block">
                            <label class="form-label small fw-bold" id="paid_to_id_label">Payee</label>
                            <select class="form-select" name="paid_to_id" id="paid_to_id_select">
                                <option value="">Select...</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-none" id="invoice_id_block">
                            <label class="form-label small fw-bold">Invoice Reference <small class="fw-normal text-muted">(Approved)</small></label>
                            <select class="form-select" name="invoice_id" id="invoice_id_select">
                                <option value="">— Select Invoice (optional) —</option>
                            </select>
                            <div class="form-text text-muted" id="invoice_id_hint"></div>
                        </div>
                        <div class="col-md-6 d-none" id="payroll_id_block">
                            <label class="form-label small fw-bold">Payroll Reference <small class="fw-normal text-muted">(Approved, Unpaid)</small></label>
                            <select class="form-select" name="payroll_id" id="payroll_id_select">
                                <option value="">— Select Payroll (optional) —</option>
                            </select>
                            <div class="form-text text-muted" id="payroll_id_hint"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="expense_amount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Paid From <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="bank_account_id" id="expense_bank_account_id" required>
                                <option value="">Select account…</option>
                                <?php foreach ($bank_accounts as $acc): ?>
                                    <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_name'] . (!empty($acc['account_code']) ? ' (' . $acc['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">The cash/bank account the money is paid from.</div>
                        </div>
                        <?php if ($enable_projects == '1'): ?>
                        <div class="col-md-6" id="project_field_block">
                            <label class="form-label small fw-bold">Project</label>
                            <select class="form-select select2-static" name="project_id">
                                <option value="">Select Project</option>
                                <?php foreach ($projects as $proj): ?>
                                    <option value="<?= $proj['project_id'] ?>"><?= htmlspecialchars($proj['project_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <!-- Description / Context -->
                        <div class="col-12">
                            <label class="form-label small fw-bold">Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" rows="3" required placeholder="Explain why this expense happened (e.g. Fuel for Truck T102-ABC)"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Additional details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="closeAddExpenseModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-check-circle"></i> <span id="btnText">Save Expense</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts Section -->
<!-- DataTables CSS/JS -->
<link rel="stylesheet" href="/assets/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="/assets/css/responsive.bootstrap5.min.css">
<link rel="stylesheet" href="/assets/css/buttons.bootstrap5.min.css">

<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>
<script src="/assets/js/dataTables.responsive.min.js"></script>
<script src="/assets/js/responsive.bootstrap5.min.js"></script>

<!-- DataTables Buttons -->
<script src="/assets/js/dataTables.buttons.min.js"></script>
<script src="/assets/js/buttons.bootstrap5.min.js"></script>
<script src="/assets/js/jszip.min.js"></script>
<script src="/assets/js/buttons.html5.min.js"></script>
<script src="/assets/js/buttons.print.min.js"></script>

<!-- Select2 -->
<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Expenses List', 'User viewed the expenses management list');

    const userPermissions = {
        canEdit: <?= canEdit('expenses') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('expenses') ? 'true' : 'false' ?>
    };

    const suppliersData      = <?= json_encode(array_map(fn($s) => ['id' => $s['supplier_id'], 'name' => $s['supplier_name']], $suppliers)) ?>;
    const staffData          = <?= json_encode(array_map(fn($e) => ['id' => $e['employee_id'], 'name' => trim($e['first_name'] . ' ' . $e['last_name'])], $employees)) ?>;
    const subContractorsData = <?= json_encode(array_map(fn($s) => ['id' => $s['supplier_id'], 'name' => $s['supplier_name']], $sub_contractors)) ?>;

    // Initialize Select2 Static
    function initSelect2() {
        $('.select2-static').each(function() {
            const $this = $(this);
            const isFilter = $this.attr('id') === 'categoryFilter';
            
            $this.select2({
                theme: 'bootstrap-5',
                dropdownParent: isFilter ? null : $('#addExpenseModal'),
                placeholder: 'Select...',
                allowClear: true,
                width: '100%'
            });
        });
    }
    initSelect2();

    // Custom mobile card renderer
    function renderMobileCards(api) {
        if ($(window).width() > 768) {
            $('#mobile-expense-cards').hide();
            $('#expensesTable_wrapper').show();
            return;
        }
        $('#expensesTable_wrapper').hide();
        const container = $('#mobile-expense-cards').empty().show();
        api.rows({ page: 'current' }).every(function() {
            const d = this.data();
            const date = d.expense_date ? new Date(d.expense_date).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : '-';
            const amount = typeof formatCurrency === 'function' ? formatCurrency(d.amount) : d.amount;
            const statusMap = { pending: 'warning', reviewed: 'primary', approved: 'success', paid: 'info', rejected: 'danger' };
            const statusBadge = `<span class="badge bg-${statusMap[d.status] || 'secondary'}">${(d.status||'').charAt(0).toUpperCase()+(d.status||'').slice(1)}</span>`;
            let actions = `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button><ul class="dropdown-menu dropdown-menu-end shadow-sm"><li><a class="dropdown-item" href="<?= getUrl('expenses/details') ?>?id=${d.expense_id}"><i class="bi bi-eye text-info"></i> View Details</a></li>`;
            <?php if (canEdit('expenses')): ?>
            if (d.status === 'pending' || d.status === 'reviewed') {
                actions += `<li><a class="dropdown-item" href="#" onclick="editExpense(${d.expense_id})"><i class="bi bi-pencil text-primary"></i> Edit</a></li>`;
            }
            if (d.status === 'pending') {
                actions += `<li><a class="dropdown-item" href="#" onclick="updateStatus(${d.expense_id}, 'reviewed')"><i class="bi bi-search text-info"></i> Review</a></li>`;
            } else if (d.status === 'reviewed') {
                actions += `<li><a class="dropdown-item" href="#" onclick="updateStatus(${d.expense_id}, 'approved')"><i class="bi bi-check-circle text-success"></i> Approve</a></li>`;
                actions += `<li><a class="dropdown-item text-danger" href="#" onclick="updateStatus(${d.expense_id}, 'rejected')"><i class="bi bi-x-circle"></i> Reject</a></li>`;
            } else if (d.status === 'approved') {
                actions += `<li><a class="dropdown-item" href="#" onclick="updateStatus(${d.expense_id}, 'paid')"><i class="bi bi-cash text-success"></i> Mark Paid</a></li>`;
            }
            <?php endif; ?>
            <?php if (canDelete('expenses')): ?>
            actions += `<li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${d.expense_id})"><i class="bi bi-trash"></i> Delete</a></li>`;
            <?php endif; ?>
            actions += `</ul></div>`;
            container.append(`
                <div class="expense-mobile-card mb-2">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div><strong class="d-block" style="font-size:0.85rem">${escapeHtml(d.description||'-')}</strong><small class="text-muted">${date}</small></div>
                        <div class="d-flex align-items-center gap-2">${statusBadge}${actions}</div>
                    </div>
                    <div class="d-flex flex-wrap gap-1" style="font-size:0.78rem">
                        ${d.categories && Array.isArray(d.categories) && d.categories.length > 0
                            ? d.categories.map(cat => { const p = cat.category_path || cat.category_name || cat.name; const leaf = p.includes(' › ') ? p.split(' › ').pop() : p; return `<span class="small text-dark">${escapeHtml(leaf)}</span>`; }).join(', ')
                            : (d.category_name ? `<span class="small text-dark">${escapeHtml(d.category_name)}</span>` : '')
                        }
                        <span class="text-danger fw-bold">${amount}</span>
                        ${d.daily_category_total && parseFloat(d.daily_category_total) !== parseFloat(d.amount) ? `<span class="text-muted ms-1" style="font-size:0.7rem">Day: ${typeof formatCurrency==='function'?formatCurrency(d.daily_category_total):d.daily_category_total}</span>` : ''}
                        ${d.paid_to_name ? `<span class="text-muted"><i class="bi bi-person"></i> ${escapeHtml(d.paid_to_name)}</span>` : ''}

                    </div>
                </div>
            `);
        });
    }

    const table = $('#expensesTable').DataTable({
        responsive: false,
        serverSide: true,
        processing: true,
        ajax: {
            url: '<?= buildUrl('api/get_expenses.php') ?>',
            data: d => {
                d.expense_account_id = $('#categoryFilter').val();
                d.status = $('#statusFilter').val();
                d.date_from = $('#dateFromFilter').val();
                d.date_to = $('#dateToFilter').val();
            },
            dataSrc: json => {
                $('#stat-total-expenses').text(formatCurrency(json.totalExpenses));
                $('#stat-month-total').text(formatCurrency(json.monthTotal));
                $('#stat-year-total').text(formatCurrency(json.yearTotal));
                $('#stat-total-records').text(json.recordsTotal);
                $('#stat-total-records-badge').text(json.recordsTotal + ' records');
                setTimeout(resizeTextToFit, 10);
                return json.data;
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'text-center text-muted small fw-bold',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            { 
                data: 'expense_date',
                width: '110px',
                render: data => `${new Date(data).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'})}`
            },
            { 
                data: 'description',
                width: '20%',
                render: (data, t, row) => `<div><strong>${escapeHtml(data)}</strong>${row.notes ? `<br><small class="text-muted text-truncate d-inline-block" style="max-width:200px">${escapeHtml(row.notes)}</small>` : ''}</div>`
            },
            {
                data: 'categories',
                width: '15%',
                orderable: false,
                render: (data, t, row) => {
                    if (data && Array.isArray(data) && data.length > 0) {
                        return data.map(cat => {
                            const path = cat.category_path || cat.category_name || cat.name;
                            const leaf = path.includes(' › ') ? path.split(' › ').pop() : path;
                            return `<span class="small text-dark">${escapeHtml(leaf)}</span>`;
                        }).join('<br>');
                    }
                    if (row.category_name) {
                        return `<span class="small text-dark">${escapeHtml(row.category_name)}</span>`;
                    }
                    return '<span class="text-muted small">—</span>';
                }
            },
            <?php if ($enable_projects == '1'): ?>
            {
                data: 'project_name',
                width: '12%',
                render: data => data ? `<span class="small text-dark">${escapeHtml(data)}</span>` : '<span class="text-muted small">—</span>'
            },
            <?php endif; ?>
            {
                data: 'amount',
                width: '110px',
                render: (data, t, row) => {
                    let html = `<strong class="text-danger">${formatCurrency(data)}</strong>`;
                    if (row.daily_category_total && parseFloat(row.daily_category_total) !== parseFloat(data)) {
                        html += `<br><small class="text-muted" style="font-size:0.65rem" title="Daily total for this category">Day: ${formatCurrency(row.daily_category_total)}</small>`;
                    }
                    return html;
                }
            },
            { 
                data: 'paid_to_name',
                width: '12%',
                render: (data, t, row) => {
                    if (row.paid_to_type === 'supplier') {
                        return `<div><span class="badge bg-primary-soft text-primary border border-primary small mb-1">Supplier</span><br><strong>${escapeHtml(data || row.vendor || 'N/A')}</strong></div>`;
                    } else if (row.paid_to_type === 'staff') {
                        return `<div><span class="badge bg-info-soft text-info border border-info small mb-1">Staff</span><br><strong>${escapeHtml(data || row.vendor || 'N/A')}</strong></div>`;
                    }
                    return `<strong>${escapeHtml(data || row.vendor || 'N/A')}</strong>`;
                }
            },

            { 
                data: 'status',
                width: '80px',
                render: data => {
                    if (!data) return '<span class="badge bg-secondary">Unknown</span>';
                    const label = data.charAt(0).toUpperCase() + data.slice(1);
                    return `<span class="badge bg-${getStatusBadgeClass(data)}">${label}</span>`;
                }
            },
            {
                data: null,
                width: '50px',
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => {
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li><a class="dropdown-item" href="<?= getUrl('expenses/details') ?>?id=${row.expense_id}"><i class="bi bi-eye text-info"></i> View Details</a></li>
                            <li><a class="dropdown-item" href="#" onclick="printVoucher(${row.expense_id})"><i class="bi bi-printer text-secondary"></i> Print Voucher</a></li>`;
                    
                    if (userPermissions.canEdit && (row.status === 'pending' || row.status === 'reviewed')) {
                        html += `<li><hr class="dropdown-divider opacity-50"></li>
                                 <li><a class="dropdown-item" href="#" onclick="editExpense(${row.expense_id})"><i class="bi bi-pencil text-primary"></i> Edit Expense</a></li>`;
                    }
                    
                    if (userPermissions.canEdit) {
                        if (row.status === 'pending') {
                            html += `<li><a class="dropdown-item" href="#" onclick="updateStatus(${row.expense_id}, 'reviewed')"><i class="bi bi-search text-info"></i> Mark as Reviewed</a></li>`;
                        } else if (row.status === 'reviewed') {
                            html += `<li><a class="dropdown-item" href="#" onclick="updateStatus(${row.expense_id}, 'approved')"><i class="bi bi-check-circle text-success"></i> Approve</a></li>`;
                            html += `<li><a class="dropdown-item" href="#" onclick="updateStatus(${row.expense_id}, 'rejected')"><i class="bi bi-x-circle text-danger"></i> Reject</a></li>`;
                        } else if (row.status === 'approved') {
                            html += `<li><a class="dropdown-item" href="#" onclick="updateStatus(${row.expense_id}, 'paid')"><i class="bi bi-cash text-success"></i> Mark as Paid</a></li>`;
                        }
                    }
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider opacity-50"></li>
                                 <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${row.expense_id})"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        dom: 'rtipB',
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        drawCallback: function() {
            renderMobileCards(this.api());
        },
        buttons: [
            {
                extend: 'copy',
                className: 'd-none',
                exportOptions: { 
                    columns: ':not(:last-child)',
                    format: {
                        body: function (data, row, column, node) {
                            // Strip HTML and keep clean text for Export
                            return node.innerText || node.textContent;
                        }
                    }
                }
            },
            {
                extend: 'print',
                className: 'd-none',
                title: '',
                messageTop: `<div class="text-center mb-4">
                        <?php if(!empty($c_logo)): ?>
                            <div class="mb-3">
                                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                            </div>
                        <?php endif; ?>
                        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= htmlspecialchars($c_name) ?></h1>
                        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Expense Management Report</h2>
                        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: ${new Date().toLocaleString()}</p>
                        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
                    </div>`,
                exportOptions: { 
                    columns: ':not(:last-child)',
                    format: {
                        body: function (data, row, column, node) {
                            return node.innerText || node.textContent;
                        }
                    }
                },
                customize: function (win) {
                    $(win.document.body).css('font-size', '10pt').css('padding', '20px');
                    $(win.document.body).find('table')
                        .addClass('compact')
                        .css('font-size', 'inherit')
                        .css('border', '1px solid #dee2e6');
                    
                    // Style Header
                    $(win.document.body).find('thead th').css({
                        'background-color': '#fff',
                        'color': '#000',
                        'font-weight': 'bold',
                        'padding': '12px',
                        'border': '1px solid #333'
                    });

                    // Style Body Cells (extend lines down)
                    $(win.document.body).find('tbody td').css({
                        'border': '1px solid #333',
                        'padding': '8px'
                    });

                    // Add alternating row colors
                    $(win.document.body).find('tbody tr:odd').css('background-color', '#f8f9fa');

                    // Rebuild stats cards for print: label on top, value below — no overflow/clipping
                    var labels = ['TOTAL EXPENSES', 'THIS MONTH', 'THIS YEAR', 'RECORDS'];
                    var ids    = ['stat-total-expenses', 'stat-month-total', 'stat-year-total', 'stat-total-records'];
                    var printCardsHtml = '<div style="display:flex; flex-direction:row; flex-wrap:nowrap; width:100%; gap:10px; margin-bottom:16px;">';
                    labels.forEach(function(label, i) {
                        var val = $('#' + ids[i]).text() || '0';
                        printCardsHtml += '<div style="flex:1 1 25%; max-width:25%;">'
                            + '<div style="border:1px solid #badbcc; background-color:#d1e7dd; border-radius:6px; padding:8px 10px; text-align:center; -webkit-print-color-adjust:exact; print-color-adjust:exact;">'
                            + '<p style="margin:0 0 4px 0; font-size:7pt; font-weight:700; text-transform:uppercase; color:#555; white-space:nowrap; letter-spacing:0.05em;">' + label + '</p>'
                            + '<p style="margin:0; font-size:11pt; font-weight:800; color:#0f5132; word-break:break-word; white-space:normal; line-height:1.2;">' + val + '</p>'
                            + '</div></div>';
                    });
                    printCardsHtml += '</div>';
                    $(win.document.body).find('table').before(printCardsHtml);

                    // Inject Robust Print Styles to ensure footer is NOT movable
                    $(win.document.body).append(`
                        <style>
                            @media print {
                                html, body { height: 100%; margin: 0 !important; padding: 0 !important; }
                                .bms-print-footer {
                                    display: flex !important;
                                    position: fixed !important;
                                    bottom: 0 !important;
                                    left: 0 !important;
                                    right: 0 !important;
                                    width: 100% !important;
                                    padding: 1mm 10mm !important;
                                    border-top: 1px solid #ccc !important;
                                    background: #fff !important;
                                    flex-direction: column !important;
                                    align-items: center !important;
                                    text-align: center !important;
                                    z-index: 9999 !important;
                                    -webkit-print-color-adjust: exact !important;
                                    print-color-adjust: exact !important;
                                }
                                .bms-print-footer {
                                    display: flex !important;
                                    position: fixed !important;
                                    bottom: 0 !important;
                                    left: 0 !important;
                                    right: 0 !important;
                                    width: 100% !important;
                                    padding: 0.5mm 10mm !important;
                                    border-top: 1px solid #ddd !important;
                                    background: #fff !important;
                                    flex-direction: column !important;
                                    align-items: center !important;
                                    text-align: center !important;
                                    z-index: 9999 !important;
                                    -webkit-print-color-adjust: exact !important;
                                    print-color-adjust: exact !important;
                                }
                                .bpf-text { font-size: 10pt !important; color: #444 !important; margin: 0 !important; line-height: 1.3 !important; display: block !important; }
                                .bpf-blue { color: #0d6efd !important; font-weight: 700 !important; }
                                body::after { content: ""; display: block; height: 10mm; } /* Buffer for footer */
                            }
                        </style>
                        <div class="bms-print-footer">
                            <span class="bpf-text">
                                This document was <strong>Printed</strong> by <strong><?= htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= htmlspecialchars($_SESSION['user_role'] ?? 'User') ?></strong> on ${(function(){ const n=new Date(); const d=n.getDate().toString().padStart(2,'0'); const m=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][n.getMonth()]; const y=n.getFullYear(); const h=n.getHours().toString().padStart(2,'0'); const mi=n.getMinutes().toString().padStart(2,'0'); const s=n.getSeconds().toString().padStart(2,'0'); return d+' '+m+' '+y+' at '+h+':'+mi+':'+s; })()}
                            </span>
                            <span class="bpf-text bpf-blue">
                                Powered by BJP Technologies &copy; ${new Date().getFullYear()}, All Rights Reserved.
                            </span>
                        </div>
                    `);

                    // Add Table Footer Buffer to reserve space on every page
                    $(win.document.body).find('table').append(`
                        <tfoot style="display: table-footer-group !important;">
                            <tr><td colspan="100%" style="height: 10mm; border: none !important; background: transparent !important;"></td></tr>
                        </tfoot>
                    `);

                    // Ensure digits are responsive in print stats
                    $(win.document.body).find('.auto-resize').css({
                        'display': 'inline-block',
                        'width': '100%',
                        'overflow': 'hidden',
                        'text-overflow': 'ellipsis',
                        'white-space': 'nowrap',
                        'font-size': '12pt' // Base size for print
                    });
                }
            }
        ],
    });

    $('#addExpenseForm').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const expenseId = $form.find('input[name="expense_id"]').val();
        const url = expenseId ? '/api/update_expense.php' : '/api/add_expense.php';
        const $btn = $form.find('button[type="submit"]');
        
        // Collect breakdown items into hidden JSON field before serialize
        const bdItems = [];
        $('#breakdown-body tr').each(function() {
            const desc = $(this).find('.item-desc').val().trim();
            if (desc) {
                bdItems.push({
                    description: desc,
                    units:   $(this).find('.item-units').val(),
                    qty:     $(this).find('.item-qty').val(),
                    price:   $(this).find('.item-price').val(),
                    tax_pct: $(this).find('.item-tax').val()
                });
            }
        });
        $('#expense_items_json').val(JSON.stringify(bdItems));

        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: url,
            type: 'POST',
            data: $form.serialize(),
            success: response => {
                $btn.prop('disabled', false).html(expenseId ? '<i class="bi bi-check-circle"></i> Update Expense' : '<i class="bi bi-check-circle"></i> Save Expense');
                if (response.success) {
                    const actionType = expenseId ? 'Updated Expense' : 'Created Expense';
                    const actionDesc = expenseId ? 'User updated expense #' + expenseId : 'User created a new expense record';
                    logReportAction(actionType, actionDesc);
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK',
                        timer: 3000
                    }).then(() => {
                        closeAddExpenseModal();
                        table.ajax.reload();
                    });
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            },
            error: () => {
                Swal.fire('Error', 'Server error occurred', 'error');
                $btn.prop('disabled', false).html(expenseId ? '<i class="bi bi-check-circle"></i> Update Expense' : '<i class="bi bi-check-circle"></i> Save Expense');
            }
        });
    });

    $('#addExpenseModal').on('hidden.bs.modal', function() {
        const $form = $('#addExpenseForm');
        $form[0].reset();
        $form.find('input[name="expense_id"]').remove();
        $form.find('.select2-static').val(null).trigger('change');
        $('#addExpenseModalLabel').html('<i class="bi bi-plus-circle"></i> Add New Expense');
        $('#btnText').text('Save Expense');
        $form.find('button[type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> <span id="btnText">Save Expense</span>');
        $('#add-expense-message').html('');
        // Reset breakdown
        $('#breakdown-body').empty();
        $('#breakdown-grand-total').text('0.00');
        $('#expense_items_json').val('');
        // Reset paid-to unified dropdown
        $('#paid_to_type').val(null).trigger('change');
        $('#paid_to_id_block').addClass('d-none');
        const $payeeSelect = $('#paid_to_id_select');
        if ($payeeSelect.data('select2')) $payeeSelect.select2('destroy');
        $payeeSelect.empty().append('<option value="">Select...</option>');
        // Reset invoice dropdown
        const $invSelect = $('#invoice_id_select');
        if ($invSelect.data('select2')) $invSelect.select2('destroy');
        $invSelect.empty().append('<option value="">— Select Invoice (optional) —</option>');
        $('#invoice_id_hint').text('');
        $('#invoice_id_block').addClass('d-none');
        // Reset payroll dropdown
        const $prlSelect = $('#payroll_id_select');
        if ($prlSelect.data('select2')) $prlSelect.select2('destroy');
        $prlSelect.empty().append('<option value="">— Select Payroll (optional) —</option>');
        $('#payroll_id_hint').text('');
        $('#payroll_id_block').addClass('d-none');

        // Reset categorization fields — also restore project block visibility
        $('#project_field_block').removeClass('d-none');
        if ($('#ex_type_id').data('select2')) { $('#ex_type_id').val(null).trigger('change'); }
        else { $('#ex_type_id').val('').trigger('change'); }
        $('#quick_add_type_cont').hide();
        $('#new_type_name').val('');
        $('#category_cascade_container').empty();
        $('#selected_category_id').val('');
        $('.add-expense-category-block').hide();
    });

    // Handle auto-add from budget details
    const urlParams = new URLSearchParams(window.location.search);
    const autoAdd = urlParams.get('auto_add');
    const categoryName = urlParams.get('categoryName');
    
    if (autoAdd === 'true') {
        $('#addExpenseModal').modal('show');
        if (categoryName) {
            // Find matched account by name
            $('[name="expense_account_id"] option').each(function() {
                const optText = $(this).text().toLowerCase();
                if (optText.includes(categoryName.toLowerCase())) {
                    $('[name="expense_account_id"]').val($(this).val()).trigger('change');
                    return false;
                }
            });
        }
    }

    // Handle project parameter for auto-selection
    const projectIdFromUrl = urlParams.get('project');
    if (projectIdFromUrl) {
        $('#addExpenseModal').modal('show');
        const projectSelect = $('select[name="project_id"]');
        if (projectSelect.length) {
            projectSelect.val(projectIdFromUrl).trigger('change');
        }
    }

    // ── Expense Breakdown event listeners (functions defined globally below) ──
    $(document).on('input', '#breakdown-body .item-qty, #breakdown-body .item-price, #breakdown-body .item-tax', function() {
        calcBreakdownRow($(this).closest('tr'));
    });

    $(document).on('click', '.remove-bd-row', function() {
        $(this).closest('tr').remove();
        renumberBreakdown();
        updateBreakdownTotal();
    });

    // Handle Paid To Toggle
    $('#paid_to_type').on('change', function() {
        const type     = $(this).val();
        const $block   = $('#paid_to_id_block');
        const $select  = $('#paid_to_id_select');
        const labelMap = { supplier: 'Supplier', staff: 'Staff Member', sub_contractor: 'Sub Contractor' };
        const dataMap  = { supplier: suppliersData, staff: staffData, sub_contractor: subContractorsData };

        if ($select.data('select2')) $select.select2('destroy');
        $select.empty().append('<option value="">Select...</option>');
        resetInvoiceBlock();
        resetPayrollBlock();

        if (type && dataMap[type]) {
            dataMap[type].forEach(d => $select.append(`<option value="${d.id}">${d.name}</option>`));
            $('#paid_to_id_label').text(labelMap[type] || 'Payee');
            $block.removeClass('d-none');
            $select.select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#addExpenseModal'),
                placeholder: 'Select...',
                allowClear: true,
                width: '100%'
            });
        } else {
            $block.addClass('d-none');
        }
    });

    // When a payee is selected, load their approved invoices (supplier/sub_contractor) or approved payrolls (staff)
    $('#paid_to_id_select').on('change', function() {
        const payeeId   = $(this).val();
        const payeeType = $('#paid_to_type').val();
        resetInvoiceBlock();
        resetPayrollBlock();
        if (!payeeId) return;

        if (['supplier', 'sub_contractor'].includes(payeeType)) {
            const $invSelect = $('#invoice_id_select');
            $invSelect.empty().append('<option value="">Loading...</option>');
            $('#invoice_id_block').removeClass('d-none');

            $.getJSON('<?= buildUrl('api/account/get_payee_invoices.php') ?>', { payee_type: payeeType, payee_id: payeeId }, function(res) {
                $invSelect.empty().append('<option value="">— Select Invoice (optional) —</option>');
                if (res.success && res.data.length) {
                    res.data.forEach(inv => {
                        $invSelect.append(`<option value="${inv.id}" data-amount="${inv.amount}">${inv.label}</option>`);
                    });
                    $('#invoice_id_hint').text(res.data.length + ' approved invoice(s) available');
                } else {
                    $('#invoice_id_hint').text('No approved invoices for this payee');
                }
                if ($invSelect.data('select2')) $invSelect.select2('destroy');
                $invSelect.select2({ theme: 'bootstrap-5', dropdownParent: $('#addExpenseModal'), placeholder: '— Select Invoice (optional) —', allowClear: true, width: '100%' });
                if (_pendingInvoiceId) {
                    $invSelect.val(_pendingInvoiceId).trigger('change.select2');
                    _pendingInvoiceId = null;
                }
            }).fail(function() {
                // Never leave the box stuck on "Loading…": on any HTTP/parse error
                // resolve to a clear "Not available" and re-init Select2.
                $invSelect.empty().append('<option value="">Not available</option>');
                $('#invoice_id_hint').text('Could not load invoices for this payee');
                if ($invSelect.data('select2')) $invSelect.select2('destroy');
                $invSelect.select2({ theme: 'bootstrap-5', dropdownParent: $('#addExpenseModal'), placeholder: 'Not available', allowClear: true, width: '100%' });
            });

        } else if (payeeType === 'staff') {
            const $prlSelect = $('#payroll_id_select');
            $prlSelect.empty().append('<option value="">Loading...</option>');
            $('#payroll_id_block').removeClass('d-none');

            $.getJSON('<?= buildUrl('api/account/get_employee_payrolls.php') ?>', { employee_id: payeeId, current_payroll_id: _pendingPayrollId || 0 }, function(res) {
                $prlSelect.empty().append('<option value="">— Select Payroll (optional) —</option>');
                if (res.success && res.data.length) {
                    res.data.forEach(p => {
                        $prlSelect.append(`<option value="${p.id}" data-amount="${p.amount}">${p.label}</option>`);
                    });
                    $('#payroll_id_hint').text(res.data.length + ' approved payroll(s) available');
                } else {
                    $('#payroll_id_hint').text('No approved, unpaid payroll for this staff');
                }
                if ($prlSelect.data('select2')) $prlSelect.select2('destroy');
                $prlSelect.select2({ theme: 'bootstrap-5', dropdownParent: $('#addExpenseModal'), placeholder: '— Select Payroll (optional) —', allowClear: true, width: '100%' });
                if (_pendingPayrollId) {
                    $prlSelect.val(_pendingPayrollId).trigger('change.select2');
                    _pendingPayrollId = null;
                }
            }).fail(function() {
                // Never leave the box stuck on "Loading…": on any HTTP/parse error
                // (e.g. an out-of-scope staff 403) resolve to a clear "Not available".
                $prlSelect.empty().append('<option value="">Not available</option>');
                $('#payroll_id_hint').text('Not available for this staff');
                if ($prlSelect.data('select2')) $prlSelect.select2('destroy');
                $prlSelect.select2({ theme: 'bootstrap-5', dropdownParent: $('#addExpenseModal'), placeholder: 'Not available', allowClear: true, width: '100%' });
            });
        }
    });

    // Auto-fill amount when invoice is selected
    $('#invoice_id_select').on('change', function() {
        const amount = $(this).find('option:selected').data('amount');
        if (amount) $('#expense_amount').val(parseFloat(amount).toFixed(2));
    });

    // Auto-fill amount when payroll is selected
    $('#payroll_id_select').on('change', function() {
        const amount = $(this).find('option:selected').data('amount');
        if (amount) $('#expense_amount').val(parseFloat(amount).toFixed(2));
    });

    // Prevent accidental closes — only allow via the explicit close functions
    $('#addExpenseModal').on('hide.bs.modal', function(e) {
        if (!_addExpenseCloseFlag) e.preventDefault();
        _addExpenseCloseFlag = false;
    });

    function resetInvoiceBlock() {
        const $invSelect = $('#invoice_id_select');
        if ($invSelect.data('select2')) $invSelect.select2('destroy');
        $invSelect.empty().append('<option value="">— Select Invoice (optional) —</option>');
        $('#invoice_id_hint').text('');
        $('#invoice_id_block').addClass('d-none');
    }

    function resetPayrollBlock() {
        const $prlSelect = $('#payroll_id_select');
        if ($prlSelect.data('select2')) $prlSelect.select2('destroy');
        $prlSelect.empty().append('<option value="">— Select Payroll (optional) —</option>');
        $('#payroll_id_hint').text('');
        $('#payroll_id_block').addClass('d-none');
    }
});

// ── Expense Categorization Logic (Dynamic) ──────────────────────────────
let expenseSchema = [];

// Inline "Other (add new…)" — define a new type / category / sub-category right
// from the dropdown. Reuses the existing schema-management API (no DB change).
// Same absolute path style as loadExpenseSchema's get_expense_schema call below,
// so both resolve identically regardless of how the app's base path is computed.
const MANAGE_SCHEMA_URL = '/api/finance/manage_expense_schema.php';
const EXPENSE_CAN_MANAGE_SCHEMA = <?= (canEdit('expenses') || canEdit('categories')) ? 'true' : 'false' ?>;
const OTHER_VALUE = '__other__';
function otherOption() {
    return EXPENSE_CAN_MANAGE_SCHEMA
        ? `<option value="${OTHER_VALUE}">➕ Other (add new…)</option>`
        : '';
}

function loadExpenseSchema(callback) {
    $.getJSON('/api/finance/get_expense_schema.php', function(res) {
        if (res.success) {
            expenseSchema = res.data;
            populateExpenseTypeDropdowns();
            if (callback) callback();
        }
    });
}

function populateExpenseTypeDropdowns() {
    const $types = $('.expense-type-sel');
    let options = '<option value="">Select Type</option>';

    expenseSchema.forEach(type => {
        options += `<option value="${type.id}">${type.name}</option>`;
    });
    options += otherOption();

    $types.html(options);

    $types.each(function() {
        const $t = $(this);
        if ($t.data('select2')) $t.select2('destroy');
        $t.select2({
            theme: 'bootstrap-5',
            dropdownParent: $t.closest('.modal').length ? $t.closest('.modal') : null,
            placeholder: 'Select Type',
            allowClear: true,
            width: '100%'
        });
    });
}

$(document).on('change', '.expense-type-sel', function() {
    const typeId = $(this).val();

    // "Other (add new…)" — define a new expense type inline, then re-select it.
    if (typeId === OTHER_VALUE) {
        defineNewType($(this));
        return;
    }
    $(this).data('prevValue', typeId);   // remembered so a cancelled "Other" can revert

    const $catBlock = $('.add-expense-category-block');

    $('#category_cascade_container .cascade-cat-select').each(function() {
        if ($(this).data('select2')) $(this).select2('destroy');
    });
    $('#category_cascade_container').empty();
    $('#selected_category_id').val('');

    // Show/hide project field based on show_project flag from DB
    const $projBlock = $('#project_field_block');
    if ($projBlock.length) {
        const typeData = expenseSchema.find(t => t.id == typeId);
        if (typeId && typeData && typeData.show_project == 0) {
            $projBlock.addClass('d-none');
            const $projSel = $projBlock.find('select');
            if ($projSel.data('select2')) $projSel.val(null).trigger('change');
            else $projSel.val('');
        } else {
            $projBlock.removeClass('d-none');
        }
    }

    if (!typeId) {
        $catBlock.hide();
        return;
    }

    const typeData = expenseSchema.find(t => t.id == typeId);
    if (!typeData || !typeData.categories || typeData.categories.length === 0) {
        $catBlock.hide();
        return;
    }

    $catBlock.show();
    renderCascadeDropdown(typeData.categories, 0);
});

function renderCascadeDropdown(categories, level) {
    $('#category_cascade_container .cascade-level').filter(function() {
        return parseInt($(this).data('level')) >= level;
    }).each(function() {
        $(this).find('.cascade-cat-select').each(function() {
            if ($(this).data('select2')) $(this).select2('destroy');
        });
        $(this).remove();
    });

    const isRoot = level === 0;
    const label = isRoot ? 'Select Category' : 'Select Sub-category';

    let opts = `<option value="">— ${label} —</option>`;
    categories.forEach(cat => {
        const hasKids = (cat.children && cat.children.length > 0) ? '1' : '0';
        opts += `<option value="${cat.id}" data-has-children="${hasKids}">${cat.name}</option>`;
    });
    opts += otherOption();

    const $wrapper = $('<div class="cascade-level" data-level="' + level + '">');

    if (!isRoot) {
        $wrapper.css({ 'padding-left': (level * 14) + 'px', 'border-left': '2px solid #0d6efd', 'margin-left': '6px' });
        $wrapper.append(
            $('<div class="d-flex align-items-center mt-2 mb-1">').html(
                `<i class="bi bi-arrow-return-right text-primary me-1" style="font-size:0.8rem;"></i>
                 <span class="text-primary small fw-semibold">${label}</span>`
            )
        );
    }

    const $sel = $(`<select class="form-select form-select-sm cascade-cat-select" data-level="${level}">`).html(opts);
    $wrapper.append($sel);

    $wrapper.hide();
    $('#category_cascade_container').append($wrapper);

    $sel.select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#addExpenseModal'),
        placeholder: label,
        allowClear: true,
        width: '100%'
    });

    $wrapper.slideDown(180);
}

$(document).on('change', '.cascade-cat-select', function() {
    const level = parseInt($(this).data('level'));
    const catId = $(this).val();

    // "Other (add new…)" — define a new category / sub-category at this level inline.
    if (catId === OTHER_VALUE) {
        defineNewCategory($(this), level);
        return;
    }

    const hasChildren = $(this).find(':selected').data('has-children') == '1';

    $('#category_cascade_container .cascade-level').filter(function() {
        return parseInt($(this).data('level')) > level;
    }).slideUp(150, function() {
        $(this).find('.cascade-cat-select').each(function() {
            if ($(this).data('select2')) $(this).select2('destroy');
        });
        $(this).remove();
    });

    $('#selected_category_id').val(catId || '');

    if (catId && hasChildren) {
        const typeId = $('#ex_type_id').val();
        const typeData = expenseSchema.find(t => t.id == typeId);
        if (typeData) {
            const cat = findCatInTree(typeData.categories, parseInt(catId));
            if (cat && cat.children && cat.children.length > 0) {
                renderCascadeDropdown(cat.children, level + 1);
                $('#selected_category_id').val('');
            }
        }
    }
});

// ── Inline "Other (add new…)" — define a new type / category / sub-category ──
// Saves via the existing manage_expense_schema API (no DB change), reloads the
// schema, re-selects the new value, and preserves every other field on the form.
function defineNewType($sel) {
    const prevType = $sel.data('prevValue') || '';
    const prevCat  = $('#selected_category_id').val() || '';
    Swal.fire({
        title: 'New Expense Type', input: 'text', inputLabel: 'Type name',
        inputPlaceholder: 'e.g. Utilities', inputAttributes: { autocomplete: 'off' },
        showCancelButton: true, confirmButtonText: 'Add', confirmButtonColor: '#0d6efd',
        // Render inside the expense modal so its focus trap does not block typing.
        target: document.getElementById('addExpenseModal') || undefined, heightAuto: false,
        didOpen: () => { const i = Swal.getInput(); if (i) i.focus(); },
        inputValidator: v => { if (!v || !v.trim()) return 'Please enter a name.'; }
    }).then(r => {
        if (!r.isConfirmed) {
            // Revert: restore the previous type (and its category path) — nothing else touched.
            if (prevType) { $sel.val(prevType).trigger('change'); if (prevCat) setTimeout(() => populateCascadeForCategory(parseInt(prevCat)), 150); }
            else { $sel.val('').trigger('change'); }
            return;
        }
        const name = r.value.trim();
        $.post(MANAGE_SCHEMA_URL, { action: 'add_type', name: name, show_project: 1 }, function (res) {
            if (res && res.success) {
                loadExpenseSchema(() => { $('#ex_type_id').val(res.id).trigger('change'); showToast('success', 'Expense type added.'); });
            } else { Swal.fire('Error', (res && res.message) || 'Could not add type.', 'error'); $sel.val(prevType || '').trigger('change'); }
        }, 'json').fail(() => { Swal.fire('Error', 'Could not add type.', 'error'); $sel.val(prevType || '').trigger('change'); });
    });
}

function defineNewCategory($sel, level) {
    const typeId = $('#ex_type_id').val();
    if (!typeId || typeId === OTHER_VALUE) { $sel.val('').trigger('change.select2'); return; }
    // Parent = the category selected one level up (null at the root level).
    let parentId = null;
    if (level > 0) {
        parentId = $('#category_cascade_container .cascade-level[data-level="' + (level - 1) + '"] .cascade-cat-select').val() || null;
    }
    const isSub = level > 0;
    Swal.fire({
        title: isSub ? 'New Sub-category' : 'New Category', input: 'text',
        inputLabel: isSub ? 'Sub-category name' : 'Category name', inputAttributes: { autocomplete: 'off' },
        showCancelButton: true, confirmButtonText: 'Add', confirmButtonColor: '#0d6efd',
        target: document.getElementById('addExpenseModal') || undefined, heightAuto: false,
        didOpen: () => { const i = Swal.getInput(); if (i) i.focus(); },
        inputValidator: v => { if (!v || !v.trim()) return 'Please enter a name.'; }
    }).then(r => {
        if (!r.isConfirmed) { $sel.val('').trigger('change.select2'); return; }
        const name = r.value.trim();
        $.post(MANAGE_SCHEMA_URL, { action: 'add_category', type_id: typeId, parent_id: parentId, name: name }, function (res) {
            if (res && res.success) {
                loadExpenseSchema(() => {
                    // Reload reset the type dropdown — restore it (display only), show the
                    // category block, then rebuild the cascade selecting the new item.
                    $('#ex_type_id').val(typeId).trigger('change.select2');
                    $('.add-expense-category-block').show();
                    populateCascadeForCategory(parseInt(res.id));
                    showToast('success', isSub ? 'Sub-category added.' : 'Category added.');
                });
            } else { Swal.fire('Error', (res && res.message) || 'Could not add category.', 'error'); $sel.val('').trigger('change.select2'); }
        }, 'json').fail(() => { Swal.fire('Error', 'Could not add category.', 'error'); $sel.val('').trigger('change.select2'); });
    });
}

function populateCascadeForCategory(catId) {
    const typeId = $('#ex_type_id').val();
    const typeData = expenseSchema.find(t => t.id == typeId);
    if (!typeData) return;

    function buildPath(cats, targetId, path) {
        for (var i = 0; i < cats.length; i++) {
            if (cats[i].id == targetId) return path.concat([cats[i]]);
            if (cats[i].children && cats[i].children.length) {
                var found = buildPath(cats[i].children, targetId, path.concat([cats[i]]));
                if (found) return found;
            }
        }
        return null;
    }

    var path = buildPath(typeData.categories, catId, []);
    if (!path) return;

    $('#category_cascade_container .cascade-cat-select').each(function() {
        if ($(this).data('select2')) $(this).select2('destroy');
    });
    $('#category_cascade_container').empty();
    var currentCats = typeData.categories;
    path.forEach(function(node, idx) {
        var isRoot = idx === 0;
        var label = isRoot ? 'Select Category' : 'Select Sub-category';
        var opts = '<option value="">— ' + label + ' —</option>';
        currentCats.forEach(function(cat) {
            var hasKids = (cat.children && cat.children.length > 0) ? '1' : '0';
            var sel = cat.id == node.id ? ' selected' : '';
            opts += '<option value="' + cat.id + '" data-has-children="' + hasKids + '"' + sel + '>' + cat.name + '</option>';
        });
        opts += otherOption();

        var $wrapper = $('<div class="cascade-level" data-level="' + idx + '">');
        if (!isRoot) {
            $wrapper.css({ 'padding-left': (idx * 14) + 'px', 'border-left': '2px solid #0d6efd', 'margin-left': '6px' });
            $wrapper.append(
                $('<div class="d-flex align-items-center mt-2 mb-1">').html(
                    '<i class="bi bi-arrow-return-right text-primary me-1" style="font-size:0.8rem;"></i>' +
                    '<span class="text-primary small fw-semibold">' + label + '</span>'
                )
            );
        }
        var $sel = $('<select class="form-select form-select-sm cascade-cat-select" data-level="' + idx + '">').html(opts);
        $wrapper.append($sel);
        $('#category_cascade_container').append($wrapper);
        $sel.select2({
            theme: 'bootstrap-5',
            dropdownParent: $('#addExpenseModal'),
            placeholder: label,
            allowClear: true,
            width: '100%'
        });
        currentCats = node.children || [];
    });

    $('#selected_category_id').val(catId);
}

let _addExpenseCloseFlag = false;
let _pendingInvoiceId   = null;
let _pendingPayrollId   = null;
function closeAddExpenseModal() {
    _addExpenseCloseFlag = true;
    $('#addExpenseModal').modal('hide');
}

function findCatInTree(cats, id) {
    for (var i = 0; i < cats.length; i++) {
        if (cats[i].id == id) return cats[i];
        if (cats[i].children && cats[i].children.length > 0) {
            var found = findCatInTree(cats[i].children, id);
            if (found) return found;
        }
    }
    return null;
}

// Initial Load
$(document).ready(function() {
    loadExpenseSchema();
});

// ── Expense Breakdown (global — called from onclick and editExpense) ──────────
function addBreakdownRow() {
    const idx = $('#breakdown-body tr').length + 1;
    const row = `<tr>
        <td class="text-center align-middle small fw-bold">${idx}</td>
        <td><input type="text" class="form-control form-control-sm item-desc" placeholder="Item description"></td>
        <td><input type="text" class="form-control form-control-sm item-units" placeholder="e.g. Litres"></td>
        <td><input type="number" class="form-control form-control-sm item-qty" step="0.01" min="0" value="1" placeholder="1"></td>
        <td><input type="number" class="form-control form-control-sm item-price" step="0.01" min="0" placeholder="0.00"></td>
        <td><input type="number" class="form-control form-control-sm item-tax" step="0.01" min="0" max="100" value="0" placeholder="0"></td>
        <td class="item-total text-end align-middle small fw-bold">0.00</td>
        <td class="text-center align-middle"><button type="button" class="btn btn-outline-danger btn-sm remove-bd-row py-0 px-1"><i class="bi bi-trash"></i></button></td>
    </tr>`;
    $('#breakdown-body').append(row);
}

function renumberBreakdown() {
    $('#breakdown-body tr').each(function(i) { $(this).find('td:first').text(i + 1); });
}

function calcBreakdownRow($tr) {
    const qty   = parseFloat($tr.find('.item-qty').val()) || 0;
    const price = parseFloat($tr.find('.item-price').val()) || 0;
    const tax   = parseFloat($tr.find('.item-tax').val()) || 0;
    const total = qty * price * (1 + tax / 100);
    $tr.find('.item-total').text(total.toFixed(2));
    updateBreakdownTotal();
}

function updateBreakdownTotal() {
    let grand = 0;
    $('#breakdown-body tr').each(function() { grand += parseFloat($(this).find('.item-total').text()) || 0; });
    $('#breakdown-grand-total').text(grand.toFixed(2));
    if (grand > 0) $('#expense_amount').val(grand.toFixed(2));
}

function applyFilters() { $('#expensesTable').DataTable().ajax.reload(); }
function clearFilters() {
    $('#categoryFilter').val(null).trigger('change');
    $('#statusFilter').val('');
    $('#dateFromFilter, #dateToFilter').val('');
    $('#expensesTable').DataTable().ajax.reload();
}

function editExpense(id) {
    logReportAction('Initiated Expense Edit', 'User clicked edit for expense record #' + id);
    $.get('/api/get_expense.php', { id: id }, response => {
        if (response.success) {
            const data = response.data;
            const $form = $('#addExpenseForm');
            $form.append(`<input type="hidden" name="expense_id" value="${id}">`);
            
            $form.find('input[name="expense_date"]').val(data.expense_date);
            $form.find('input[name="amount"]').val(data.amount);
            $form.find('textarea[name="description"]').val(data.description);
            $form.find('textarea[name="notes"]').val(data.notes);

            if (data.type_id) {
                $form.find('select[name="expense_type"]').val(data.type_id).trigger('change');
                setTimeout(() => {
                    const catId = data.category_ids && data.category_ids.length > 0 ? parseInt(data.category_ids[0]) : null;
                    if (catId) populateCascadeForCategory(catId);
                }, 150);
            }

            // Populate Paid To (unified dropdown)
            if (data.paid_to_type) {
                _pendingInvoiceId = data.invoice_id || null;
                _pendingPayrollId = data.payroll_id || null;
                $form.find('select[name="paid_to_type"]').val(data.paid_to_type).trigger('change');
                setTimeout(() => {
                    $('#paid_to_id_select').val(data.paid_to_id).trigger('change');
                }, 150);
            } else {
                _pendingInvoiceId = null;
                _pendingPayrollId = null;
                $form.find('select[name="paid_to_type"]').val(null).trigger('change');
            }

            if (data.expense_account_id) {
                $form.find('select[name="expense_account_id"]').val(data.expense_account_id).trigger('change');
            }
            // Populate Paid From (source bank/cash account)
            if (data.bank_account_id) {
                $form.find('select[name="bank_account_id"]').val(data.bank_account_id).trigger('change');
            }
            // Populate Project if enabled
            if (data.project_id) {
                $form.find('select[name="project_id"]').val(data.project_id).trigger('change');
            } else {
                $form.find('select[name="project_id"]').val('').trigger('change');
            }

            // Populate breakdown items if available
            $('#breakdown-body').empty();
            $('#breakdown-grand-total').text('0.00');
            if (data.expense_items && Array.isArray(data.expense_items) && data.expense_items.length > 0) {
                data.expense_items.forEach(() => addBreakdownRow());
                $('#breakdown-body tr').each(function(i) {
                    const item = data.expense_items[i];
                    if (!item) return;
                    $(this).find('.item-desc').val(item.description || '');
                    $(this).find('.item-units').val(item.units || '');
                    $(this).find('.item-qty').val(item.qty || 1);
                    $(this).find('.item-price').val(item.price || 0);
                    $(this).find('.item-tax').val(item.tax_pct || 0);
                    calcBreakdownRow($(this));
                });
            }
            
            $('#addExpenseModalLabel').html('<i class="bi bi-pencil"></i> Edit Expense');
            $('#btnText').text('Update Expense');
            $('#addExpenseModal').modal('show');
        } else {
            Swal.fire('Error', response.message, 'error');
        }
    });
}

function printVoucher(id) {
    logReportAction('Print Voucher', 'User printed payment voucher for expense #' + id);

    $.get('/api/get_expense.php', { id: id }, function(response) {
        if (!response.success) { Swal.fire('Error', response.message || 'Could not load expense', 'error'); return; }
        const d = response.data;

        // ── Amount in words helper ──────────────────────────────────────
        function numToWords(n) {
            const a = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                       'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                       'Seventeen','Eighteen','Nineteen'];
            const b = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
            n = Math.floor(parseFloat(n) || 0);
            if (n === 0) return 'Zero';
            if (n < 20)  return a[n];
            if (n < 100) return b[Math.floor(n/10)] + (n%10 ? ' ' + a[n%10] : '');
            if (n < 1000) return a[Math.floor(n/100)] + ' Hundred' + (n%100 ? ' ' + numToWords(n%100) : '');
            if (n < 1000000) return numToWords(Math.floor(n/1000)) + ' Thousand' + (n%1000 ? ' ' + numToWords(n%1000) : '');
            return numToWords(Math.floor(n/1000000)) + ' Million' + (n%1000000 ? ' ' + numToWords(n%1000000) : '');
        }

        const amount   = parseFloat(d.amount) || 0;
        const amtWords = numToWords(amount) + ' Only';
        const fmtAmt   = amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const voucherNo = 'PV-' + String(d.expense_id).padStart(5, '0');
        const date = d.expense_date ? new Date(d.expense_date).toLocaleDateString('en-US', { day:'2-digit', month:'long', year:'numeric' }) : '-';
        const paidTo = d.paid_to_name || d.vendor || '-';
        const printedBy = '<?= htmlspecialchars(($_SESSION["first_name"] ?? "") . " " . ($_SESSION["last_name"] ?? "")) ?>';
        const printedRole = '<?= htmlspecialchars($_SESSION["user_role"] ?? "User") ?>';
        const now = new Date();
        const printDate = now.getDate().toString().padStart(2,'0') + ' ' +
            ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][now.getMonth()] +
            ' ' + now.getFullYear() + ' at ' +
            now.getHours().toString().padStart(2,'0') + ':' +
            now.getMinutes().toString().padStart(2,'0') + ':' +
            now.getSeconds().toString().padStart(2,'0');

        const logoHtml = '<?= $_pv_logo_js ?>';
        const cName    = '<?= addslashes(htmlspecialchars($c_name)) ?>';

        // ── Build Voucher HTML ──────────────────────────────────────────
        const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>Payment Voucher - ${voucherNo}</title>
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family: Arial, sans-serif; font-size: 10pt; color: #222; background:#fff; padding:15mm 15mm 20mm 15mm; }

            /* Header */
            .pv-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #0d6efd; padding-bottom:10px; margin-bottom:14px; }
            .pv-logo-area { display:flex; flex-direction:column; gap:4px; }
            .pv-company  { font-size:16pt; font-weight:800; color:#0d6efd; text-transform:uppercase; }
            .pv-title-area { text-align:right; }
            .pv-title    { font-size:14pt; font-weight:800; text-transform:uppercase; color:#333; letter-spacing:2px; }
            .pv-voucher-no { font-size:9pt; color:#666; margin-top:4px; }
            .pv-date     { font-size:9pt; color:#333; font-weight:600; margin-top:2px; }

            /* Amount box */
            .pv-amount-box { background:#f0f7ff; border:2px solid #0d6efd; border-radius:6px; padding:10px 16px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; }
            .pv-amount-label { font-size:8pt; text-transform:uppercase; color:#555; }
            .pv-amount-value { font-size:20pt; font-weight:900; color:#0d6efd; }
            .pv-amount-words { font-size:8.5pt; color:#333; font-style:italic; text-align:right; }

            /* Details table */
            .pv-table { width:100%; border-collapse:collapse; margin-bottom:14px; }
            .pv-table tr { border-bottom:1px solid #eee; }
            .pv-table td { padding:6px 8px; vertical-align:top; font-size:9.5pt; }
            .pv-table td:first-child { width:35%; font-weight:700; color:#555; text-transform:uppercase; font-size:8.5pt; }
            .pv-table td:last-child  { color:#222; }

            /* Status badge */
            .pv-status { display:inline-block; padding:2px 10px; border-radius:20px; font-size:8pt; font-weight:700; text-transform:uppercase; }
            .pv-status-pending  { background:#fff3cd; color:#856404; border:1px solid #ffc107; }
            .pv-status-approved { background:#d1e7dd; color:#0f5132; border:1px solid #198754; }
            .pv-status-paid     { background:#cfe2ff; color:#084298; border:1px solid #0d6efd; }
            .pv-status-rejected { background:#f8d7da; color:#842029; border:1px solid #dc3545; }

            /* Signature section */
            .pv-signatures { display:flex; justify-content:space-between; margin-top:24px; gap:20px; }
            .pv-sig-block  { flex:1; text-align:center; }
            .pv-sig-line   { border-top:1px solid #333; margin-bottom:4px; margin-top:30px; }
            .pv-sig-label  { font-size:8pt; text-transform:uppercase; color:#555; font-weight:700; }
            .pv-sig-name   { font-size:8pt; color:#333; margin-top:2px; }

            /* Footer */
            .pv-footer { position:fixed; bottom:0; left:0; right:0; padding:3mm 15mm; border-top:1px solid #ccc; background:#fff; text-align:center; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .pv-footer p { font-size:7pt; margin:0; line-height:1.4; }
            .pv-footer .pv-powered { color:#0d6efd; font-weight:700; }
            .pv-spacer { height:15mm; }

            /* Note box */
            .pv-note { background:#fffbee; border-left:3px solid #ffc107; padding:6px 10px; font-size:8.5pt; color:#555; margin-bottom:14px; border-radius:0 4px 4px 0; }

            @media print {
                body { padding:10mm 12mm 18mm 12mm; }
                .pv-footer { position:fixed; bottom:0; }
            }
        </style></head><body>

        <!-- HEADER -->
        <div class="pv-header">
            <div class="pv-logo-area">
                ${logoHtml}
                <span class="pv-company">${cName}</span>
            </div>
            <div class="pv-title-area">
                <div class="pv-title">Payment Voucher</div>
                <div class="pv-voucher-no">Voucher No: <strong>${voucherNo}</strong></div>
                <div class="pv-date">Date: <strong>${date}</strong></div>
            </div>
        </div>

        <!-- AMOUNT BOX -->
        <div class="pv-amount-box">
            <div>
                <div class="pv-amount-label">Amount Paid</div>
                <div class="pv-amount-value">${fmtAmt}</div>
            </div>
            <div class="pv-amount-words">
                <div style="font-size:7.5pt; color:#888; margin-bottom:2px;">In Words:</div>
                <strong>${amtWords}</strong>
            </div>
        </div>

        <!-- DETAILS TABLE -->
        <table class="pv-table">
            <tr><td>Paid To</td><td><strong>${d.paid_to_name || d.vendor || '-'}</strong>${d.paid_to_type ? ' <span style="font-size:8pt;color:#888;">('+d.paid_to_type+')</span>' : ''}</td></tr>
            <tr><td>Description</td><td>${d.description || '-'}</td></tr>
            <tr><td>Expense Account</td><td>${d.expense_account_name || '-'}</td></tr>
            <tr><td>Paid From (Bank)</td><td>${d.bank_account_name || '-'}</td></tr>
            <tr><td>Reference No.</td><td>${d.reference_number || '-'}</td></tr>
            ${d.notes ? `<tr><td>Notes</td><td>${d.notes}</td></tr>` : ''}
            <tr><td>Status</td><td><span class="pv-status pv-status-${d.status||'pending'}">${(d.status||'pending').charAt(0).toUpperCase()+(d.status||'pending').slice(1)}</span></td></tr>
            <tr><td>Prepared By</td><td>${d.created_by_name || '-'}</td></tr>
        </table>

        <!-- NOTE -->
        <div class="pv-note">
            <strong>Note:</strong> This is a computer-generated payment voucher. Please verify all details before processing payment.
        </div>

        <!-- SIGNATURES -->
        <div class="pv-signatures">
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">Prepared By</div>
                <div class="pv-sig-name">${d.created_by_name || ''}</div>
            </div>
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">Approved By</div>
                <div class="pv-sig-name">&nbsp;</div>
            </div>
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">Received By</div>
                <div class="pv-sig-name">${paidTo}</div>
            </div>
        </div>

        <!-- BUFFER -->
        <div class="pv-spacer"></div>

        <!-- FOOTER -->
        <div class="pv-footer">
            <p>This document was <strong>Printed</strong> by <strong>${printedBy} - ${printedRole}</strong> on ${printDate}</p>
            <p class="pv-powered">Powered by BJP Technologies &copy; ${now.getFullYear()}, All Rights Reserved.</p>
        </div>

        <script>window.onload = function() { window.print(); };<\/script>
        </body></html>`;

        const win = window.open('', '_blank', 'width=850,height=650');
        win.document.write(html);
        win.document.close();

    }, 'json');
}

function updateStatus(id, status) {
    Swal.fire({
        title: 'Update Status?',
        text: `Are you sure you want to mark this as ${status}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Proceed'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/update_expense_status.php', { expense_id: id, status: status }, response => {
                if (response.success) {
                    logReportAction('Updated Expense Status', 'User updated status of expense record #' + id + ' to ' + status);
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: response.message,
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK',
                        timer: 3000
                    }).then(() => {
                        $('#expensesTable').DataTable().ajax.reload();
                    });
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            });
        }
    });
}

function confirmDelete(id) {
    Swal.fire({
        title: 'Delete Expense?',
        text: 'Permanently delete this expense? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/delete_expense.php', { expense_id: id }, response => {
                if (response.success) {
                    logReportAction('Deleted Expense Record', 'User deleted expense record #' + id);
                    Swal.fire({
                        icon: 'success',
                        title: 'Deleted!',
                        text: 'Expense record deleted.',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK',
                        timer: 3000
                    }).then(() => {
                        $('#expensesTable').DataTable().ajax.reload();
                    });
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            });
        }
    });
}

function formatCurrency(v) { return parseFloat(v).toLocaleString('en-US', {minimumFractionDigits: 2}); }
function getStatusBadgeClass(s) {
    return s === 'approved' ? 'success' : s === 'reviewed' ? 'primary' : s === 'pending' ? 'warning' : s === 'rejected' ? 'danger' : s === 'paid' ? 'info' : 'secondary';
}
function escapeHtml(t) { return $('<div>').text(t).html(); }
function exportExpenses() {
    const params = {
        expense_account_id: $('#categoryFilter').val(),
        status: $('#statusFilter').val(),
        date_from: $('#dateFromFilter').val(),
        date_to: $('#dateToFilter').val()
    };
    const queryString = $.param(params);
    logReportAction('Exported Expenses', 'User exported expenses list to CSV/Excel');
    window.location.href = '<?= getUrl('expenses/export') ?>?' + queryString;
}

function copyTable() {
    const table = document.getElementById('expensesTable');
    if (!table) return;
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    
    try {
        document.execCommand('copy');
        logReportAction('Copied Expenses Table', 'User copied expenses table to clipboard');
        Swal.fire({
            icon: 'success',
            title: 'Copied!',
            text: 'Table copied to clipboard',
            confirmButtonColor: '#28a745',
            confirmButtonText: 'OK',
            timer: 3000
        });
    } catch(err) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to copy table' });
    }
    window.getSelection().removeAllRanges();
}

function printTable() {
    logReportAction('Printed Expenses Table', 'User generated a printed report of the expenses table');
    $('#expensesTable').DataTable().button('.buttons-print').trigger();
}

function showToast(type, msg) {
    if (!$('.toast-container').length) $('body').append('<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999"></div>');
    const $t = $(`<div class="toast align-items-center text-white bg-${type==='error'?'danger':type} border-0" role="alert"><div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
    $('.toast-container').append($t);
    new bootstrap.Toast($t[0]).show();
    $t.on('hidden.bs.toast', () => $t.remove());
}
</script>

<style>
.bg-success-soft {
    background-color: rgba(25, 135, 84, 0.1) !important;
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
.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

@media print {
    #print-stats-cards {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        width: 100% !important;
        gap: 10px !important;
        margin-bottom: 20px !important;
    }
    #print-stats-cards > div {
        flex: 1 1 25% !important;
        max-width: 25% !important;
        width: 25% !important;
    }
    .custom-stat-card {
        border: 1px solid #badbcc !important;
        background-color: #d1e7dd !important;
        -webkit-print-color-adjust: exact;
    }
    .auto-resize {
        font-size: 10pt !important;
        overflow-wrap: anywhere !important;
        white-space: normal !important;
        word-break: break-all !important;
    }
}
.table thead th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #333;
    font-weight: 600;
}
.btn-outline-secondary:hover i {
    color: white !important;
}
.action-dropdown .dropdown-item { padding: 0.5rem 1rem; }
.action-dropdown .dropdown-item i { margin-right: 0.5rem; }
.select2-container--bootstrap-5 .select2-selection { border-radius: 0.375rem; }
/* Custom Mobile Layout for DataTables Footer */
@media (max-width: 768px) {
    #expensesTable_wrapper .row:last-child {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        margin-top: 10px;
    }
    #expensesTable_wrapper .row:last-child > div {
        width: auto !important;
        flex: 1 !important;
        padding: 0 5px !important;
        display: flex !important;
        align-items: center !important;
    }
    .dataTables_info {
        font-size: 0.65rem !important;
        padding-top: 0 !important;
        white-space: nowrap !important;
        text-align: left !important;
        color: #666;
    }
    .dataTables_paginate {
        display: flex !important;
        justify-content: flex-end !important;
        text-align: right !important;
    }
    .pagination {
        font-size: 0.65rem !important;
        margin-bottom: 0 !important;
    }
    }
    .page-link {
        padding: 0.2rem 0.4rem !important;
    }
}

/* ── Mobile custom card view ── */
@media (max-width: 768px) {
    .expense-mobile-card {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 8px 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
}
</style>

<?php includeFooter(); 
ob_end_flush();
?>