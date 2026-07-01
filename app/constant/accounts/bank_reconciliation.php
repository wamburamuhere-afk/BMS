<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';


// Enforce permission BEFORE any output
autoEnforcePermission('bank_reconciliation');

// Include the header
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View bank reconciliation', 'User viewed the bank reconciliation management list');

// Fetch company settings for print
$c_logo = getSetting('company_logo', '');
$c_name = getSetting('company_name', 'BMS');

// Permission flags for UI elements
$can_edit_reconciliation = isAdmin() || canEdit('bank_reconciliation');
$can_finalize_reconciliation = isAdmin() || canEdit('bank_reconciliation'); // Or specific logic if needed

// Bank account dropdowns (filter + both modals) now load via AJAX Select2 from
// api/account/search_bank_accounts.php, so the old all-accounts query (which also
// joined the non-existent banks.bank_id) is no longer needed.

// Get current period (default to current month)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$bank_account_id = isset($_GET['bank_account_id']) ? (int)$_GET['bank_account_id'] : null;

// Pre-render only the currently-selected account so the AJAX Select2 shows it on
// reload (label = "CODE — Name"); the rest of the list loads via AJAX.
$selected_bank_label = '';
if ($bank_account_id) {
    $sb = $pdo->prepare("SELECT account_code, account_name FROM accounts WHERE account_id = ?");
    $sb->execute([$bank_account_id]);
    if ($r = $sb->fetch(PDO::FETCH_ASSOC)) {
        $selected_bank_label = $r['account_code'] . ' — ' . $r['account_name'];
    }
}

// Helper functions


?>

<div class="container-fluid py-4 px-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
       
        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Bank Reconciliation Report</h2>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-print-none">
            <h2 class="fw-bold mb-1"><i class="bi bi-bank me-2 text-primary"></i>Bank Reconciliation</h2>
            <p class="text-muted mb-0">Reconcile bank statements with your accounting records</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($can_edit_reconciliation): ?>
            <button type="button" class="btn btn-outline-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#importStatementModal">
                <i class="bi bi-upload"></i> Import Statement
            </button>
            <button type="button" class="btn btn-primary shadow-sm" onclick="openNewReconciliation()">
                <i class="bi bi-plus-circle"></i> New Reconciliation
            </button>
            <?php endif; ?>
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

    <!-- Statistics Cards -->
    <div class="row mb-4" id="print-stats-cards">
        <?php
        $stats_query = "SELECT COUNT(*) as total_reconciliations, SUM(CASE WHEN status = 'reconciled' THEN 1 ELSE 0 END) as reconciled, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status = 'disputed' THEN 1 ELSE 0 END) as disputed FROM bank_reconciliations WHERE 1=1";
        $stats_params = [];
        if ($bank_account_id) { $stats_query .= " AND bank_account_id = ?"; $stats_params[] = $bank_account_id; }
        $stats_stmt = $pdo->prepare($stats_query); $stats_stmt->execute($stats_params); $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        ?>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Total Reconciliations</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= $stats['total_reconciliations'] ?? 0 ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Reconciled</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= $stats['reconciled'] ?? 0 ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Pending</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= $stats['pending'] ?? 0 ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Disputed</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= $stats['disputed'] ?? 0 ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 border-0 shadow-sm d-print-none">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filters & Search</h6>
        </div>
        <div class="card-body">
            <form id="reconciliationFilterForm" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Bank Account</label>
                    <select class="form-select" id="bank_account_id" name="bank_account_id" style="width:100%;">
                        <option value="">All Bank Accounts</option>
                        <?php if ($bank_account_id && $selected_bank_label !== ''): ?>
                        <option value="<?= (int)$bank_account_id ?>" selected><?= safe_output($selected_bank_label) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="reconciled">Reconciled</option>
                        <option value="disputed">Disputed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-filter me-1"></i> Apply Filter
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="clearFilters()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                    </button>
                </div>
            </form>
        </div>
    </div>



    <!-- Section Title -->
    <div class="mb-3 d-print-none">
        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Reconciliation Records</h5>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printReconciliations()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" id="filter_limit" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#reconciliationsTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
        <div>
            <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill">
                <i class="bi bi-check-circle-fill me-1"></i> <?= $stats['total_reconciliations'] ?? 0 ?> Records
            </span>
        </div>
    </div>

    <!-- Reconciliation List -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <div class="table-responsive">
                <table id="reconciliationsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light">
                        <tr>
                            <th style="width:50px;" class="ps-4">S/NO</th>
                            <th class="ps-4">ID</th>
                            <th>Account</th>
                            <th>Date</th>
                            <th class="text-end">Statement Bal</th>
                            <th class="text-end">Difference</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-4 d-print-none">Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- Reconciliation Modal (New & Edit) -->
<?php if ($can_edit_reconciliation): ?>
<div class="modal fade" id="reconciliationModal" tabindex="-1" aria-labelledby="reconciliationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title fw-bold" id="reconciliationModalLabel">
                    <i class="bi bi-plus-circle me-2"></i> New Bank Reconciliation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="reconciliationForm">
                <input type="hidden" id="reconciliation_id" name="reconciliation_id" value="">
                <div class="modal-body p-4">
                    <div id="reconciliation-message" class="mb-3"></div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="modal_bank_account_id" class="form-label small fw-bold text-muted text-uppercase">Bank Account <span class="text-danger">*</span></label>
                            <select class="form-select shadow-sm" id="modal_bank_account_id" name="bank_account_id" style="width:100%;" required>
                                <option value=""></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="modal_reconciliation_date" class="form-label small fw-bold text-muted text-uppercase">Reconciliation Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control shadow-sm" id="modal_reconciliation_date" name="reconciliation_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="modal_period_start" class="form-label small fw-bold text-muted text-uppercase">Period Start <span class="text-danger">*</span></label>
                            <input type="date" class="form-control shadow-sm" id="modal_period_start" name="period_start" value="<?= date('Y-m-01') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="modal_period_end" class="form-label small fw-bold text-muted text-uppercase">Period End <span class="text-danger">*</span></label>
                            <input type="date" class="form-control shadow-sm" id="modal_period_end" name="period_end" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="modal_statement_balance" class="form-label small fw-bold text-muted text-uppercase">Statement Balance <span class="text-danger">*</span></label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-light fw-bold border-end-0">TSh</span>
                                <input type="number" class="form-control border-start-0" id="modal_statement_balance" name="statement_balance" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="modal_book_balance" class="form-label small fw-bold text-muted text-uppercase">Book Balance <span class="text-danger">*</span></label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-light fw-bold border-end-0">TSh</span>
                                <input type="number" class="form-control border-start-0" id="modal_book_balance" name="book_balance" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="modal_notes" class="form-label small fw-bold text-muted text-uppercase">Notes</label>
                            <textarea class="form-control shadow-sm" id="modal_notes" name="notes" rows="3" placeholder="Any notes or comments about this reconciliation"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-outline-secondary px-4 fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" id="modalSubmitBtn">
                        <i class="bi bi-check-circle me-1"></i> Create Reconciliation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Import Statement Modal -->
<?php if ($can_edit_reconciliation): ?>
<div class="modal fade" id="importStatementModal" tabindex="-1" aria-labelledby="importStatementModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="importStatementModalLabel">
                    <i class="bi bi-upload"></i> Import Bank Statement
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="importStatementForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="import-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Supported formats: CSV, Excel (.xlsx)</li>
                            <li>Date format: YYYY-MM-DD</li>
                            <li>Amount format: 1234.56 (no currency symbols)</li>
                            <li>Transaction types: deposit, withdrawal</li>
                            <li>Maximum file size: 10MB</li>
                        </ul>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="import_bank_account_id" class="form-label">Bank Account <span class="text-danger">*</span></label>
                            <select class="form-select" id="import_bank_account_id" name="bank_account_id" style="width:100%;" required>
                                <option value=""></option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="statement_file" class="form-label">Statement File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control" id="statement_file" name="statement_file" accept=".csv,.xlsx,.xls" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="import_action" class="form-label">Import Action</label>
                            <select class="form-select" id="import_action" name="import_action">
                                <option value="add_new">Add New Transactions Only</option>
                                <option value="replace">Replace All Transactions for Period</option>
                                <option value="update">Update Existing Transactions</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_match" name="auto_match" checked>
                                <label class="form-check-label" for="auto_match">
                                    Attempt to auto-match with existing transactions
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Import Statement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Include DataTables and other scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<!-- This page re-loads jQuery above, so Select2 (loaded in the header) must be
     re-registered on this jQuery instance for the AJAX dropdown to work. -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Bank Account filter — Select2 with AJAX search, label "CODE — Name".
    $('#bank_account_id').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'All Bank Accounts',
        allowClear: true,
        ajax: {
            url: '<?= buildUrl('api/account/search_bank_accounts.php') ?>',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term || '', page: params.page || 1 }),
            processResults: (data, params) => {
                params.page = params.page || 1;
                return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
            },
            cache: true
        },
        minimumInputLength: 0
    });

    // New/Edit Reconciliation modal — same AJAX Select2, code-first label.
    // dropdownParent keeps the dropdown inside the modal (not behind it).
    $('#modal_bank_account_id').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Select Bank Account',
        dropdownParent: $('#reconciliationModal'),
        ajax: {
            url: '<?= buildUrl('api/account/search_bank_accounts.php') ?>',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term || '', page: params.page || 1 }),
            processResults: (data, params) => {
                params.page = params.page || 1;
                return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
            },
            cache: true
        },
        minimumInputLength: 0
    });

    // Import Statement modal — same AJAX Select2, code-first label.
    $('#import_bank_account_id').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Select Bank Account',
        dropdownParent: $('#importStatementModal'),
        ajax: {
            url: '<?= buildUrl('api/account/search_bank_accounts.php') ?>',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term || '', page: params.page || 1 }),
            processResults: (data, params) => {
                params.page = params.page || 1;
                return { results: data.results || [], pagination: { more: !!(data.pagination && data.pagination.more) } };
            },
            cache: true
        },
        minimumInputLength: 0
    });

    // Initialize DataTable
    $('#reconciliationsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/account/get_bank_reconciliations.php',
            type: 'POST'
        },
        columns: [
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'text-center text-muted small fw-bold ps-4',
                render: function(data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                }
            },
            { data: 'reconciliation_id' },
            { data: 'account_name' },
            { 
                data: 'reconciliation_date',
                render: function(data) {
                    return new Date(data).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                }
            },
            { 
                data: 'statement_balance',
                render: function(data) {
                    return parseFloat(data).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
            },
            { 
                data: 'difference',
                render: function(data) {
                    const val = parseFloat(data);
                    const color = Math.abs(val) > 0.01 ? (val > 0 ? 'success' : 'danger') : 'success';
                    return `<span class="text-${color} fw-bold">${val.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>`;
                }
            },
            { 
                data: 'status',
                render: function(data) {
                    const colors = {
                        'pending': 'warning',
                        'reconciled': 'success',
                        'disputed': 'danger',
                        'cancelled': 'secondary'
                    };
                    return `<span class="text-${colors[data] || 'secondary'} fw-bold">${data.charAt(0).toUpperCase() + data.slice(1)}</span>`;
                }
            },
            {
                data: 'reconciliation_id',
                className: 'text-end pe-4 d-print-none',
                orderable: false,
                render: function(data, type, row) {
                    return `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="<?= getUrl('bank-reconciliation/view') ?>?id=${data}">
                                        <i class="bi bi-eye"></i> View Details
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="#" onclick="editReconciliation(${data}); return false;">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" onclick="deleteReconciliation(${data}); return false;">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                </li>
                            </ul>
                        </div>
                    `;
                }
            }
        ],
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ records",
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        responsive: true,
        dom: 'rtip',
        pageLength: 25,
        order: [[0, 'desc']]
    });

    // Reconciliation form submission (New & Edit)
    $('#reconciliationForm').on('submit', function(e) {
        e.preventDefault();
        
        const reconciliationId = $('#reconciliation_id').val();
        const isEdit = reconciliationId !== '';
        const url = isEdit ? '/api/account/update_reconciliation.php' : '/api/account/create_reconciliation.php';
        const formData = $(this).serialize();
        const submitBtn = $('#modalSubmitBtn');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');

        $.ajax({
            url: url,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#reconciliationModal').modal('hide');
                    
                    Swal.fire({
                        title: isEdit ? 'Updated!' : 'Created!',
                        text: response.message,
                        icon: 'success',
                        confirmButtonColor: '#198754', // Green color
                        confirmButtonText: 'OK'
                    }).then((result) => {
                        if (isEdit) {
                            $('#reconciliationsTable').DataTable().ajax.reload();
                        } else {
                            window.location.href = '<?= getUrl('bank-reconciliation/view') ?>?id=' + response.reconciliation_id;
                        }
                    });
                } else {
                    $('#reconciliation-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> ' + (isEdit ? 'Update Reconciliation' : 'Create Reconciliation'));
                }
            },
            error: function(xhr, status, error) {
                $('#reconciliation-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> ' + (isEdit ? 'Update Reconciliation' : 'Create Reconciliation'));
                console.error('Error:', error);
            }
        });
    });

    // Import statement form submission
    $('#importStatementForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...');

        $.ajax({
            url: '/api/account/import_bank_statement.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#importStatementModal').modal('hide');
                    Swal.fire({
                        title: 'Imported!',
                        text: response.message,
                        icon: 'success',
                        confirmButtonColor: '#198754'
                    }).then(() => location.reload());
                } else {
                    $('#import-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Statement');
                }
            },
            error: function(xhr, status, error) {
                $('#import-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Statement');
                console.error('Error:', error);
            }
        });
    });

    // Auto-fill book balance (ledger as-of period_end) when account or period_end changes.
    function refreshBookBalance() {
        const bankAccountId = $('#modal_bank_account_id').val();
        const periodEnd     = $('#modal_period_end').val();
        if (!bankAccountId || $('#reconciliation_id').val() !== '') return;
        $.ajax({
            url: '<?= buildUrl('api/account/get_bank_balance.php') ?>',
            type: 'GET',
            data: { bank_account_id: bankAccountId, as_of: periodEnd || '' },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#modal_book_balance').val(response.book_balance);
                }
            }
        });
    }
    $('#modal_bank_account_id').on('change', refreshBookBalance);
    $('#modal_period_end').on('change', refreshBookBalance);

    // Reset forms when modals are closed
    $('#reconciliationModal').on('hidden.bs.modal', function() {
        $('#reconciliationForm')[0].reset();
        $('#reconciliation_id').val('');
        // Clear the AJAX Select2 (form.reset doesn't) and drop any injected option.
        $('#modal_bank_account_id').val(null).trigger('change');
        $('#reconciliation-message').html('');
        $('#modalSubmitBtn').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Create Reconciliation');
        $('#reconciliationModalLabel').html('<i class="bi bi-plus-circle me-2"></i> New Bank Reconciliation');
    });
    
    $('#importStatementModal').on('hidden.bs.modal', function() {
        $('#importStatementForm')[0].reset();
        $('#import_bank_account_id').val(null).trigger('change');   // clear AJAX Select2
        $('#import-message').html('');
        $('#importStatementForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Import Statement');
    });
});

function clearFilters() {
    $('#bank_account_id').val('');
    $('#start_date').val('');
    $('#end_date').val('');
    $('#status').val('');
    window.location.href = 'bank_reconciliation.php';
}

function exportReconciliation() {
    // Trigger DataTable export
    $('#reconciliationsTable').DataTable().button('.buttons-excel').trigger();
}

function downloadTemplate() {
    // Create a CSV template file for bank statements
    const headers = [
        'transaction_date', 'value_date', 'description', 'reference', 'transaction_type',
        'amount', 'balance_after', 'category', 'counterparty_name', 'counterparty_account'
    ];
    
    const sampleData = [
        ['2023-10-01', '2023-10-01', 'Salary Payment', 'SAL-001', 'deposit', '500000.00', '1500000.00', 'income', 'ABC Company', '1234567890'],
        ['2023-10-02', '2023-10-02', 'Office Rent', 'RENT-001', 'withdrawal', '250000.00', '1250000.00', 'expense', 'Landlord Inc', '9876543210']
    ];
    
    let csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\n";
    sampleData.forEach(function(row) {
        csvContent += row.join(',') + "\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "bank_statement_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function openNewReconciliation() {
    $('#reconciliation_id').val('');
    $('#reconciliationForm')[0].reset();
    $('#modal_bank_account_id').val(null).trigger('change');   // clear AJAX Select2
    $('#reconciliationModalLabel').html('<i class="bi bi-plus-circle me-2"></i> New Bank Reconciliation');
    $('#modalSubmitBtn').html('<i class="bi bi-check-circle me-1"></i> Create Reconciliation');
    $('#reconciliationModal').modal('show');
}

function editReconciliation(reconciliationId) {
    // Show spinner or something if needed
    $.ajax({
        url: '/api/account/get_reconciliation.php',
        type: 'GET',
        data: { id: reconciliationId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const data = response.data;
                $('#reconciliation_id').val(data.reconciliation_id);
                // AJAX Select2: inject the account option (with its label) before selecting.
                if (data.bank_account_id) {
                    const label = (data.account_code ? data.account_code + ' — ' : '') + (data.account_name || ('#' + data.bank_account_id));
                    const $sel = $('#modal_bank_account_id');
                    if ($sel.find("option[value='" + data.bank_account_id + "']").length === 0) {
                        $sel.append(new Option(label, data.bank_account_id, true, true));
                    }
                    $sel.val(data.bank_account_id).trigger('change');
                }
                $('#modal_reconciliation_date').val(data.reconciliation_date);
                $('#modal_period_start').val(data.period_start);
                $('#modal_period_end').val(data.period_end);
                $('#modal_statement_balance').val(data.statement_balance);
                $('#modal_book_balance').val(data.book_balance);
                $('#modal_notes').val(data.notes);
                
                $('#reconciliationModalLabel').html('<i class="bi bi-pencil-square me-2"></i> Edit Bank Reconciliation');
                $('#modalSubmitBtn').html('<i class="bi bi-save me-1"></i> Update Reconciliation');
                $('#reconciliationModal').modal('show');
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Could not fetch reconciliation details', 'error');
        }
    });
}

function finalizeReconciliation(reconciliationId) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You want to finalize this reconciliation? This action cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, finalize it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '/api/account/finalize_reconciliation.php',
                type: 'POST',
                data: { reconciliation_id: reconciliationId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            title: 'Finalized!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonColor: '#198754'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire('Error', 'An error occurred while finalizing. Please try again.', 'error');
                    console.error('Error:', error);
                }
            });
        }
    });
}

function updateStatus(reconciliationId, status) {
    const actionMap = {
        'disputed': 'mark as disputed',
        'cancelled': 'cancel',
        'reconciled': 'reconcile'
    };
    
    const action = actionMap[status] || 'update';
    
    Swal.fire({
        title: 'Are you sure?',
        text: "You want to " + action + " this reconciliation?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: status === 'disputed' ? '#ffc107' : (status === 'cancelled' ? '#6c757d' : '#198754'),
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, ' + action + ' it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '/api/account/update_reconciliation_status.php',
                type: 'POST',
                data: { 
                    reconciliation_id: reconciliationId,
                    status: status
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            title: 'Updated!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonColor: '#198754'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire('Error', 'An error occurred while updating status. Please try again.', 'error');
                    console.error('Error:', error);
                }
            });
        }
    });
}
function deleteReconciliation(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '/api/account/delete_reconciliation.php',
                type: 'POST',
                data: { reconciliation_id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            title: 'Deleted!',
                            text: 'The reconciliation record has been deleted.',
                            icon: 'success',
                            confirmButtonColor: '#198754'
                        });
                        $('#reconciliationsTable').DataTable().ajax.reload();
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'An error occurred while deleting.', 'error');
                }
            });
        }
    });
}

function copyTable() {
    const table = document.getElementById('reconciliationsTable');
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1000, showConfirmButton: false });
}

function printReconciliations() {
    window.print();
}

function exportExcel() {
    const table = document.getElementById('reconciliationsTable');
    const rows = Array.from(table.querySelectorAll('tr'));
    const csvContent = rows.map(row => {
        const cols = Array.from(row.querySelectorAll('th, td')).slice(0, -1); // Exclude actions
        return cols.map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
    }).join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', 'Bank_Reconciliations.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<style>
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
}

.bg-success-soft {
    background-color: rgba(25, 135, 84, 0.1) !important;
}

.table thead th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #f0f0f0;
    font-size: 0.8rem;
    font-weight: bold;
    color: #444;
}

.table td { vertical-align: middle; }

@media print {
    .d-print-none { display: none !important; }
    .d-print-block { display: block !important; }
    
    body { background: white; padding: 20px; font-size: 10pt; }
    .container-fluid { width: 100% !important; max-width: 100% !important; }
    .card { border: none !important; box-shadow: none !important; }
    
    #reconciliationsTable { width: 100% !important; border-collapse: collapse !important; }
    #reconciliationsTable thead { display: table-header-group; }
    table { width: 100% !important; border: 1px solid #333; }
    th, td { border: 1px solid #333 !important; padding: 8px !important; text-align: left; }
    th { background: #f8f9fa !important; font-weight: bold; -webkit-print-color-adjust: exact; }
    
    .badge {
        background: transparent !important;
        border: 1px solid #333 !important;
        color: #000 !important;
        padding: 2px 4px !important;
        display: inline-block !important;
    }
    
    #printHeader h1 {
        color: #0d6efd !important;
        text-transform: uppercase;
        font-weight: 800;
        margin: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    #printHeader h2 {
        color: #495057 !important;
        text-transform: uppercase;
        font-weight: 600;
        margin: 5px 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Hide DataTables clutter */
    .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate {
        display: none !important;
    }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    .table-responsive { font-size: 0.85rem; }
    .modal-dialog { margin: 0.5rem; }
}

@media (max-width: 576px) {
    .btn-group { width: 100%; margin-top: 0.5rem; }
    .btn-group .btn { flex: 1; }
    .col-xl-3 { margin-bottom: 0.5rem; }
}
</style>

<?php
// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>