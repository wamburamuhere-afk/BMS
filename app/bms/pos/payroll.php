<?php
// Include roots configuration
require_once dirname(__DIR__, 3) . '/roots.php';
require_once dirname(__DIR__, 3) . '/core/payment_source.php';

// Enforce permission BEFORE any output
autoEnforcePermission('payroll');

// Fetch company settings for print header
$c_name = getSetting('company_name', 'BMS');
$c_logo = getSetting('company_logo', '');

// Include the header
includeHeader();


$page_title = 'Payroll Management';

$period = $_GET['period'] ?? date('Y-m');

// Initial stats will be loaded via AJAX
$stats = [
    'total_employees' => 0,
    'paid_count' => 0,
    'pending_count' => 0,
    'total_payout' => 0
];

// Fetch Departments for Filter
$dept_stmt = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name");
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Define permissions
$can_process_payroll = isAdmin() || canEdit('payroll');
$can_approve_payroll = isAdmin() || canEdit('payroll'); // Usually Edit covers both in this simplified flag context

$selected_period = $period;
$selected_status = $_GET['status'] ?? '';
?>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-1" id="printHeader" style="margin-top: -20px !important;">
       
        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 2px 0; font-size: 12pt; letter-spacing: 1px;">PAYROLL REGISTRY REPORT</h2>
        <p style="color: #6c757d; margin: 0; font-size: 8pt;">Period: <?= date('F Y', strtotime($period . '-01')) ?> | Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 2px solid #0d6efd; margin-top: 5px; margin-bottom: 10px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block">
        <div class="row g-3 mb-4">
            <div class="col-3">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center; height: 80px; display: flex; flex-direction: column; justify-content: center; overflow: hidden;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 3px; font-weight: 600;">Active Staff</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt; overflow-wrap: break-word;" id="print-stat-total-employees">0</h3>
                </div>
            </div>
            <div class="col-3">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center; height: 80px; display: flex; flex-direction: column; justify-content: center; overflow: hidden;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 3px; font-weight: 600;">Paid Records</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt; overflow-wrap: break-word;" id="print-stat-paid-count">0</h3>
                </div>
            </div>
            <div class="col-3">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center; height: 80px; display: flex; flex-direction: column; justify-content: center; overflow: hidden;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 3px; font-weight: 600;">Pending Payout</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt; overflow-wrap: break-word;" id="print-stat-pending-count">0</h3>
                </div>
            </div>
            <div class="col-3">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 8px; text-align: center; height: 80px; display: flex; flex-direction: column; justify-content: center; overflow: hidden;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 3px; font-weight: 600;">Total Payout</p>
                    <h3 style="color: #333; font-weight: 800; margin: 0; font-size: 12pt; overflow-wrap: break-word; line-height: 1.1;" id="print-stat-total-payout">0</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="#">Purchases</a></li>
            <li class="breadcrumb-item active">Payroll</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="bi bi-cash-stack text-primary"></i> Payroll Management</h2>
                    <p class="text-muted mb-0">Manage employee salaries, deductions, and payouts</p>
                </div>
                <div class="d-grid d-md-block">
                    <a href="<?= getUrl('paye_register') ?>" class="btn btn-outline-primary px-4 shadow-sm me-md-2 mb-2 mb-md-0">
                        <i class="bi bi-person-vcard me-1"></i> PAYE Register
                    </a>
                    <a href="<?= getUrl('statutory_remittances') ?>" class="btn btn-outline-primary px-4 shadow-sm me-md-2 mb-2 mb-md-0">
                        <i class="bi bi-receipt me-1"></i> Statutory Remittances
                    </a>
                     <?php if ($can_process_payroll): ?>
                    <button class="btn btn-primary px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#processPayrollModal">
                        <i class="bi bi-plus-circle me-1"></i> Process Payroll
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 d-print-none">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-employees">0</h4>
                            <p class="mb-0">Active Staff</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-people-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-paid-count">0</h4>
                            <p class="mb-0">Paid Records</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-pending-count">0</h4>
                            <p class="mb-0">Pending Payout</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clock-history" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="mb-0 fw-bold" id="stat-total-payout">0</h5>
                            <p class="mb-0">Total Payout</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash-stack" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 d-print-none border-0 shadow-sm">
        <div class="card-header bg-light border-bottom">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel"></i> Filters & Search</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Payroll Period</label>
                    <select id="period_picker" class="form-select">
                        <?php for($i=0; $i<=6; $i++): ?>
                            <?php $p = date('Y-m', strtotime("-$i month")); ?>
                            <option value="<?= $p ?>" <?= $period === $p ? 'selected' : '' ?>><?= date('F Y', strtotime($p . '-01')) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold text-muted">Department</label>
                    <select id="dept_filter" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach($departments as $d): ?>
                            <option value="<?= $d['department_id'] ?>"><?= $d['department_name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-print-none mb-4">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <!-- Print/Excel Group -->
            <div class="btn-group shadow-sm bg-white" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printPayroll()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> <span class="d-none d-sm-inline">Print</span>
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportToExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-excel text-success me-1"></i> <span class="d-none d-sm-inline">Excel</span>
                </button>
            </div>
            
            <!-- Pagesize Group -->
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2 d-none d-md-inline"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 50px; box-shadow: none; background: transparent;" onchange="$('#payrollTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>

            <!-- Search Group -->
            <div class="input-group input-group-sm shadow-sm flex-grow-1 flex-md-grow-0" style="min-width: 200px; max-width: 300px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="payroll_search" class="form-control border-0 p-2" placeholder="Search payroll...">
            </div>

            <!-- Bulk Action Group -->
            <?php if ($can_process_payroll): ?>
            <button class="btn btn-success btn-sm shadow-sm px-3 rounded-pill ms-auto ms-md-0" onclick="bulkAction('approved')">
                <i class="bi bi-check-all"></i> <span class="d-none d-sm-inline">Bulk Approve</span><span class="d-inline d-sm-none">Approve</span>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-print-none">
            <h5 class="mb-0 fw-bold">Payroll Registry</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="payrollTable" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead>
                        <tr>
                            <th style="width: 40px;" class="ps-3"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                            <th style="width: 50px;">S/NO</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th class="text-end">Basic</th>
                            <th class="text-end">Allowance</th>
                            <th class="text-end">Gross</th>
                            <th class="text-end">Deductions</th>
                            <th class="text-end">Net Salary</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                        <tbody></tbody>
                        <tfoot>
                            <!-- Spacer for Print Footer protection -->
                            <tr class="d-none d-print-table-row" style="height: 80px; border: none !important;">
                                <td colspan="11" style="border: none !important;"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Fixed Branded Print Footer -->
      
    </div>

<!-- Process Payroll Modal -->
<div class="modal fade" id="processPayrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 p-4 text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title fw-bold"><i class="bi bi-gear-fill me-2"></i>Process Industrial Payroll</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="form_process_payroll">
                <div class="modal-body p-4 bg-light">
                    <div id="process-payroll-message"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Payroll Period</label>
                            <input type="month" class="form-control form-control-lg rounded-3 border-0 shadow-sm" id="input_payroll_period" name="payroll_period" value="<?= $period ?>" required onchange="previewAndCalculatePayroll()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Reference Date</label>
                            <input type="date" class="form-control form-control-lg rounded-3 border-0 shadow-sm" name="payroll_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Filter Department</label>
                            <select class="form-select form-control-lg rounded-3 border-0 shadow-sm" id="process_department" name="department_id" onchange="previewAndCalculatePayroll()">
                                <option value="">All Departments</option>
                                <?php foreach($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>"><?= $d['department_name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-uppercase text-muted">Employment Status</label>
                            <select class="form-select form-control-lg rounded-3 border-0 shadow-sm" id="process_employment_status" name="employment_status" onchange="previewAndCalculatePayroll()">
                                <option value="">All Active</option>
                                <option value="active">Active</option>
                                <option value="probation">Probation</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mt-4 g-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch custom-switch">
                                <input class="form-check-input" type="checkbox" name="include_allowances" id="check_allowances" checked onchange="previewAndCalculatePayroll()">
                                <label class="form-check-label fw-bold text-muted small" for="check_allowances">INCLUDE ALLOWANCES</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch custom-switch">
                                <input class="form-check-input" type="checkbox" name="include_deductions" id="check_deductions" checked onchange="previewAndCalculatePayroll()">
                                <label class="form-check-label fw-bold text-muted small" for="check_deductions">INCLUDE DEDUCTIONS & TAX</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch custom-switch">
                                <input class="form-check-input" type="checkbox" name="include_attendance" id="check_attendance" onchange="previewAndCalculatePayroll()">
                                <label class="form-check-label fw-bold text-muted small" for="check_attendance">CONSIDER ATTENDANCE</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch custom-switch">
                                <input class="form-check-input" type="checkbox" name="auto_approve" id="check_approve">
                                <label class="form-check-label fw-bold text-muted small" for="check_approve">AUTO-APPROVE RESULTS</label>
                            </div>
                        </div>
                    </div>

                    <div id="payrollPreview" class="mt-4 p-3 rounded-4 bg-white shadow-sm border" style="display:none;">
                        <h6 class="fw-bold mb-3 d-flex justify-content-between text-success">
                            <span>Calculation Preview</span>
                            <span class="badge bg-soft-success text-success px-3 rounded-pill" id="previewCount" style="background-color: #d1e7dd;">0 Employees</span>
                        </h6>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr class="small text-muted text-uppercase">
                                        <th>Employee</th>
                                        <th class="text-end">Basic</th>
                                        <th class="text-end">Allowances</th>
                                        <th class="text-end">Deductions</th>
                                        <th class="text-end">Net</th>
                                    </tr>
                                </thead>
                                <tbody id="previewBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light">
                    <button type="button" class="btn btn-outline-success rounded-pill px-4" onclick="previewAndCalculatePayroll()">
                        <i class="bi bi-eye me-2"></i>Refresh Preview
                    </button>
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 shadow">
                        <i class="bi bi-check2-circle me-2"></i>Execute Final Processing
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Payroll Modal -->
<div class="modal fade" id="editPayrollModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 p-4 text-white" style="background: var(--primary-gradient);">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Payroll Instance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editPayrollForm">
                <input type="hidden" id="edit_payroll_id" name="payroll_id">
                <div class="modal-body p-4 bg-light">
                    <div id="edit-payroll-message"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Basic Salary</label>
                            <input type="number" step="0.01" class="form-control rounded-3 border-0 shadow-sm" name="basic_salary" id="edit_basic_salary" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Allowances</label>
                            <input type="number" step="0.01" class="form-control rounded-3 border-0 shadow-sm" name="allowances" id="edit_allowances">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Deductions</label>
                            <input type="number" step="0.01" class="form-control rounded-3 border-0 shadow-sm" name="deductions" id="edit_deductions">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">Tax Amount</label>
                            <input type="number" step="0.01" class="form-control rounded-3 border-0 shadow-sm" name="tax_amount" id="edit_tax_amount">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold text-muted text-uppercase">Payment Method</label>
                            <select class="form-select rounded-3 border-0 shadow-sm" name="payment_method" id="edit_payment_method">
                                <option value="bank">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="check">Check</option>
                                <option value="mobile">Mobile Money</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 bg-light">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success rounded-pill px-4 shadow">Update Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts Section -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
// Cash/bank accounts available as a "Paid From" source for paying salaries.
window.PR_CASH_ACCOUNTS = <?= json_encode(array_map(fn($a) => [
    'id' => (int)$a['account_id'],
    'text' => $a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')
], cashBankAccounts($pdo))) ?>;

$(document).ready(function() {
    // Initialize DataTables with Invoice Style AJAX controller
    const table = $('#payrollTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        ajax: {
            url: APP_URL + '/api/get_payrolls',
            data: function(d) {
                d.period = $('#period_picker').val();
                d.department = $('#dept_filter').val();
                d.search_query = $('#payroll_search').val();
            },
            dataSrc: function(json) {
                if (json.stats) {
                    $('#stat-total-employees, #print-stat-total-employees').text(json.stats.total_employees);
                    $('#stat-paid-count, #print-stat-paid-count').text(json.stats.paid_count);
                    $('#stat-pending-count, #print-stat-pending-count').text(json.stats.pending_count);
                    const formattedPayout = 'TSh ' + parseFloat(json.stats.total_payout).toLocaleString();
                    $('#stat-total-payout, #print-stat-total-payout').text(formattedPayout);
                }
                return json.data;
            }
        },
        columns: [
            { 
                data: 'payroll_id',
                render: function(data) {
                    // Unprocessed/preview rows have no payroll_id — no selectable
                    // checkbox (a "null" value would break bulk actions on strict DBs).
                    if (data === null || data === undefined || data === '' || data === 'null') return '';
                    return `<input type="checkbox" class="record-checkbox form-check-input ms-2" value="${data}">`;
                },
                orderable: false
            },
            { 
                data: null, 
                title: 'S/NO', 
                orderable: false, 
                searchable: false,
                render: function (data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                }
            },
            { 
                data: 'first_name',
                render: function(data, type, row) {
                    return `<div>
                        <div class="fw-bold text-dark">${data} ${row.last_name}</div>
                        <div class="text-muted small">#${row.employee_number || '---'}</div>
                    </div>`;
                }
            },
            { data: 'department_name' },
            {
                data: 'basic_salary',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 0, 'TSh ')
            },
            {
                data: 'allowances',
                className: 'text-end',
                orderable: false,
                render: function(data) {
                    return `<span class="text-success">+${parseFloat(data || 0).toLocaleString()}</span>`;
                }
            },
            {
                data: 'gross_salary',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 0, 'TSh ')
            },
            { 
                data: 'deductions',
                className: 'text-end',
                render: function(data) {
                    return `<span class="text-danger">-${parseFloat(data).toLocaleString()}</span>`;
                }
            },
            { 
                data: 'net_salary',
                className: 'text-end',
                render: function(data) {
                    return `<strong class="text-dark">TSh ${parseFloat(data).toLocaleString()}</strong>`;
                }
            },
            { 
                data: 'payment_status',
                className: 'text-center',
                render: function(data) {
                    const badges = {
                        'paid': 'status-paid',
                        'approved': 'status-approved',
                        'pending': 'status-warning',
                        'processing': 'status-processing',
                        'unprocessed': 'status-unprocessed'
                    };
                    return `<span class="badge-status ${badges[data] || 'status-unprocessed'}">${data || 'unprocessed'}</span>`;
                }
            },
            {
                data: 'payroll_id',
                className: 'text-end pe-4',
                render: function(data, type, row) {
                    const status = row.payment_status || 'unprocessed';
                    let actions = `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="border-radius:12px; min-width: 160px;">
                    `;

                    if (status === 'unprocessed') {
                        actions += `<li><a class="dropdown-item py-2" href="#" onclick="processSingle(${row.employee_id}, '${$('#period_picker').val()}')"><i class="bi bi-lightning-charge me-2 text-primary"></i>Process</a></li>`;
                    } else {
                        actions += `<li><a class="dropdown-item py-2" href="payroll_details?id=${data}"><i class="bi bi-eye me-2 text-primary"></i>View Details</a></li>`;
                        actions += `<li><a class="dropdown-item py-2" href="#" onclick="editPayroll(${data})"><i class="bi bi-pencil me-2 text-info"></i>Edit Record</a></li>`;
                        actions += `<li><a class="dropdown-item py-2" href="payslip?id=${data}" target="_blank"><i class="bi bi-printer me-2 text-secondary"></i>Print Payslip</a></li>`;
                        actions += `<li><hr class="dropdown-divider"></li>`;
                        actions += `<li><a class="dropdown-item py-2 text-danger" href="#" onclick="deletePayroll(${data})"><i class="bi bi-trash me-2"></i>Delete</a></li>`;
                    }

                    actions += `</ul></div>`;
                    return actions;
                },
                orderable: false
            }
        ],
        order: [[2, 'asc']],
        dom: 'rtip',
        pageLength: 10,
        lengthChange: false // Controlled by custom selector
    });

    $('#period_picker, #dept_filter').on('change', () => {
        const period = $('#period_picker').val();
        const dept = $('#dept_filter option:selected').text();
        table.ajax.reload();
    });
    $('#payroll_search').on('keyup', function() { table.ajax.reload(); });
    $('#selectAll').on('change', function() { $('.record-checkbox').prop('checked', this.checked); });
    
    // Initialize others if needed

    // Log modal interaction
    $('#processPayrollModal').on('shown.bs.modal', function () {
    });
});

function exportToExcel() {

    
    // Collect checked IDs or clean search params
    const period = $('#period_picker').val();
    const dept = $('#dept_filter').val();
    const search = $('#payroll_search').val();
    
    // Construct export URL
    let url = APP_URL + `/api/export_payroll?period=${period}&department=${dept}&search=${search}`;
    window.location.href = url;
}

function reloadTable() { $('#payrollTable').DataTable().ajax.reload(null, false); }

function printPayroll() {

    window.print();
}

// Comprehensive Preview Logic
function previewAndCalculatePayroll() {
    const form = $('#form_process_payroll')[0];
    const formData = new FormData(form);
    const previewSection = $('#payrollPreview');
    const previewBody = $('#previewBody');
    
    previewSection.show();
    previewBody.html('<tr><td colspan="5" class="text-center py-4"><div class="spinner-border spinner-border-sm text-success me-2"></div>Calculating projections...</td></tr>');

    $.ajax({
        url: APP_URL + '/api/preview_payroll',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success && res.data.length > 0) {
                let html = '';
                res.data.forEach(emp => {
                    html += `<tr>
                        <td class="fw-bold text-dark">${emp.employee_name}</td>
                        <td class="text-end">TSh ${parseFloat(emp.basic_salary).toLocaleString()}</td>
                        <td class="text-end text-success">+${parseFloat(emp.allowances).toLocaleString()}</td>
                        <td class="text-end text-danger">-${parseFloat(emp.deductions).toLocaleString()}</td>
                        <td class="text-end fw-bold text-success">TSh ${parseFloat(emp.net_salary).toLocaleString()}</td>
                    </tr>`;
                });
                previewBody.html(html);
                $('#previewCount').text(res.data.length + ' Employees Detected');
            } else {
                previewBody.html(`<tr><td colspan="5" class="text-center py-3 text-muted">${res.message || 'No matches found.'}</td></tr>`);
            }
        }
    });
}

// Processing Execution
$('#form_process_payroll').on('submit', function(e) {
    e.preventDefault();
    const btn = $(this).find('button[type="submit"]');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Wait...');
    
    $.ajax({
        url: APP_URL + '/api/process_payroll',
        type: 'POST',
        data: new FormData(this),
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                Swal.fire({
                    title: 'Success',
                    html: `<b>${res.message}</b><br><small class='text-muted'>Reloading to update statistics...</small>`,
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
            btn.prop('disabled', false).html('<i class="bi bi-check2-circle me-2"></i>Execute Final Processing');
        }
    });
});

// Bulk Status Management
function bulkAction(status) {
    const ids = [];
    $('.record-checkbox:checked').each(function() {
        const v = $(this).val();
        // Guard against non-numeric values (e.g. a stray "null") reaching the server.
        if (v && v !== 'null' && v !== 'undefined' && !isNaN(parseInt(v, 10))) ids.push(v);
    });
    
    if (ids.length === 0) return Swal.fire('No Selection', 'Please select records first.', 'info');

    // Paying requires choosing the source account — a payment form, not one-click.
    if (status === 'paid') {
        const opts = {};
        (window.PR_CASH_ACCOUNTS || []).forEach(a => opts[a.id] = a.text);
        Swal.fire({
            title: `Pay ${ids.length} record(s)`,
            input: 'select',
            inputOptions: opts,
            inputPlaceholder: 'Paid From account…',
            text: 'Choose the cash/bank account the salaries are paid from.',
            showCancelButton: true,
            confirmButtonText: 'Pay',
            confirmButtonColor: '#0d6efd',
            inputValidator: (v) => (!v ? 'Please choose the Paid From account.' : undefined)
        }).then(r => {
            if (r.isConfirmed) postBulkPayroll(ids, status, r.value);
        });
        return;
    }

    Swal.fire({
        title: 'Bulk Action',
        text: `Apply status update for ${ids.length} records?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd'
    }).then(result => {
        if (result.isConfirmed) postBulkPayroll(ids, status, null);
    });
}

function postBulkPayroll(ids, status, paidFrom) {
    $.post(APP_URL + '/api/bulk_update_payroll_status', { payroll_ids: ids, status: status, paid_from_account_id: paidFrom }, res => {
        if (res.success) {
            Swal.fire('Updated', res.message, 'success');
            reloadTable();
        } else {
            Swal.fire('Failed', res.message, 'error');
        }
    });
}

// Single Processing Handler
function processSingle(id, period) {
    Swal.fire({
        title: 'Process Payroll',
        text: 'Instantiate record for ' + period + '?',
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#198754'
    }).then(res => {
        if (res.isConfirmed) {
            $.post(APP_URL + '/api/process_payroll', { 
                employee_ids: JSON.stringify([id]), 
                payroll_period: period,
                include_allowances: 1,
                include_deductions: 1
            }, r => {
                if (r.success) {
                    Swal.fire({
                        title: 'Success',
                        text: r.message,
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', r.message, 'error');
                }
            });
        }
    });
}

// Edit Record Logic
function editPayroll(id) {
    $.get(APP_URL + '/api/get_payroll_details', { id: id }, res => {
        if (res.success) {
            const p = res.data.payroll;
            $('#edit_payroll_id').val(p.payroll_id);
            $('#edit_basic_salary').val(p.basic_salary);
            $('#edit_allowances').val(p.allowances);
            $('#edit_deductions').val(p.deductions);
            $('#edit_tax_amount').val(p.tax_amount);
            $('#edit_payment_method').val(p.payment_method);
            $('#editPayrollModal').modal('show');
            

        }
    });
}

$('#editPayrollForm').on('submit', function(e) {
    e.preventDefault();
    $.post(APP_URL + '/api/update_payroll', $(this).serialize(), res => {
        if (res.success) {
            Swal.fire('Updated', 'Record saved successfully', 'success');
            $('#editPayrollModal').modal('hide');
            reloadTable();
        }
    });
});

// Deletion Logic
function deletePayroll(id) {
    Swal.fire({
        title: 'Delete?',
        text: "This action is permanent!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it'
    }).then(res => {
        if (res.isConfirmed) {
            $.post(APP_URL + '/api/delete_payroll', { payroll_id: id }, r => {
                if (r.success) {

                    Swal.fire('Deleted', 'The record has been purged.', 'success');
                    reloadTable();
                }
            });
        }
    });
}
</script>


<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
    overflow: hidden;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.card {
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.table thead th {
    background-color: #f8f9fa !important;
    color: #212529 !important;
    border: 1px solid #dee2e6 !important;
    font-weight: 600;
}

/* Print styles */
@media print {
    /* Page Margin & Footer Protection */
    @page {
        margin-bottom: 100px !important;
        margin-top: 25px !important;
    }

    #printHeader {
        display: block !important;
        margin-bottom: 10px;
        padding-bottom: 5px;
        margin-top: 0 !important;
    }

    .container-fluid {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }
    
    .d-print-none, 
    .navbar, 
    .card-header, 
    .btn, 
    .dropdown, 
    .dataTables_length, 
    .dataTables_filter, 
    .dataTables_info, 
    .dataTables_paginate, 
    .dt-buttons,
    .breadcrumb {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    
    .card-body {
        padding: 0 !important;
    }
    
    .table {
        font-size: 11px;
        width: 100% !important;
        border-collapse: collapse !important;
    }
    
    .table thead th {
        background-color: #f8f9fa !important;
        border: 1px solid #000 !important;
        padding: 8px 4px !important;
        font-weight: bold;
    }
    
    .table td {
        border: 1px solid #ddd !important;
        padding: 6px 4px !important;
    }
    
    tr {
        page-break-inside: avoid;
    }

    tfoot {
        display: table-footer-group !important;
    }

    .fixed-print-footer { 
        position: fixed; 
        bottom: 0; 
        left: 0; 
        right: 0; 
        width: 100%;
        text-align: center;
        background: white !important;
        padding-bottom: 15px;
        z-index: 9999;
    }

    .table th:last-child,
    .table td:last-child,
    .table th:first-child,
    .table td:first-child {
        display: none !important; /* Hide Actions and Checkbox columns */
    }
}
</style>
<?php 
// Updated styles above replacing custom CSS block
?>
<?php require_once dirname(__DIR__, 3) . '/footer.php'; ?>
