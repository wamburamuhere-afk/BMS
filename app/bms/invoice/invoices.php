<?php
// File: invoices.php
require_once __DIR__ . '/../../../roots.php';
// Enforce permission (must be before includeHeader to allow redirects)
autoEnforcePermission('invoices');

includeHeader();

// Get filter parameters for initial dropdowns
global $pdo;
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

$status_filter = $_GET['status'] ?? '';
$customer_filter = $_GET['customer'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
// Get filters from URL
$filtered_customer_id = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$payment_filter = isset($_GET['payment_status']) ? $_GET['payment_status'] : (isset($_GET['status']) ? $_GET['status'] : '');

// Fetch specific customer details if filtered
$filtered_customer = null;
$filtered_customer_name = '';
if ($filtered_customer_id > 0) {
    $c_stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
    $c_stmt->execute([$filtered_customer_id]);
    $filtered_customer = $c_stmt->fetch(PDO::FETCH_ASSOC);
    $filtered_customer_name = $filtered_customer['customer_name'] ?? '';
}


// Check projects setting
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}
?>
<style>
:root {
    --glass-bg: rgba(255, 255, 255, 0.9);
    --glass-border: rgba(255, 255, 255, 0.3);
    --primary-gradient: linear-gradient(45deg, #198754, #157347);
    --blue-gradient: linear-gradient(45deg, #0d6efd, #0b5ed7);
    --yellow-gradient: linear-gradient(45deg, #ffc107, #e0a800);
    --red-gradient: linear-gradient(45deg, #dc3545, #bb2d3b);
    --info-gradient: linear-gradient(45deg, #0dcaf0, #0aa2c0);
    --card-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.1);
}

.invoice-dashboard {
    background: #ffffff;
    min-height: 100vh;
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
.custom-stat-card small,
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}

.stats-icon {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    margin-right: 1.25rem;
    background: rgba(15, 81, 50, 0.1);
    color: #0f5132 !important;
}


.btn-premium {
    background: #198754 !important;
    color: white !important;
    border: none;
    border-radius: 10px;
    padding: 10px 24px;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-premium:hover {
    box-shadow: 0 10px 20px -10px rgba(25, 135, 84, 0.5);
    transform: scale(1.02);
}

/* Badge Styling */
.badge-premium {
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0.4em 1em;
    border-radius: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-paid { background-color: #d1e7dd !important; color: #198754 !important; }
.status-partial { background-color: #cfe2ff !important; color: #0d6efd !important; }
.status-overdue { background-color: #f8d7da !important; color: #dc3545 !important; }
.status-sent { background-color: #cff4fc !important; color: #0dcaf0 !important; }
.status-pending { background-color: #fff3cd !important; color: #ffc107 !important; border: 1px solid #ffc107; }
.status-draft { background-color: #e2e3e5 !important; color: #6c757d !important; }

.main-card {
    background: white;
    border: none;
    border-radius: 24px;
    box-shadow: var(--card-shadow);
    overflow: hidden;
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 6px;
    border-radius: 6px;
    font-weight: bold;
}
</style>

<div class="invoice-dashboard p-4 p-md-5">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
      
        <p class="text-dark mb-1 small text-uppercase">
            <?php 
            $web_email = [];
            if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
            if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
            if (!empty($web_email)) echo implode(" | ", $web_email);
            ?>
        </p>

        <p class="text-dark mb-1 small text-uppercase">
            <?php 
            $tin_vrn = [];
            if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
            if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
            if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
            ?>
        </p>

        <div class="mt-3">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;"><?= ($payment_filter == 'paid' ? 'Payments' : 'Invoices') ?> Report</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Invoices</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h1 class="fw-bold mb-1 text-dark d-print-none">
                <i class="bi bi-receipt text-success me-2 d-print-none"></i>
                <?php if ($payment_filter == 'paid'): ?>
                    Payments
                <?php else: ?>
                    Invoices
                <?php endif; ?>
                <?php if (!empty($filtered_customer_name)): ?>
                    <span class="text-primary fs-3">| <?= safe_output($filtered_customer_name) ?></span>
                    <a href="<?= getUrl('invoices') ?>" class="btn btn-sm btn-outline-secondary ms-2 rounded-pill shadow-sm d-print-none">
                        <i class="bi bi-x-circle"></i> View All
                    </a>
                <?php endif; ?>
            </h1>
            <p class="text-muted mb-0 d-print-none">
                <?php if (!empty($filtered_customer_name)): ?>
                    <?php if ($payment_filter == 'paid'): ?>
                        Viewing payment history for <strong><?= safe_output($filtered_customer_name) ?></strong>
                    <?php else: ?>
                        Showing invoices and payment status for <strong><?= safe_output($filtered_customer_name) ?></strong>
                    <?php endif; ?>
                <?php else: ?>
                    Manage customer invoices and payment history
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-3 d-print-none">
            <?php if (canView('received_invoices')): ?>
            <a href="<?= getUrl('received_invoices') ?>" class="btn btn-outline-primary btn-sm shadow-sm">
                <i class="bi bi-inbox me-1"></i> Received Invoices
            </a>
            <?php endif; ?>
            <?php if (canCreate('invoices')): ?>
            <a href="<?= getUrl('invoice_create') ?>" class="btn btn-primary btn-sm shadow-sm">
                <i class="bi bi-plus-circle me-1"></i> New Invoice
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row g-4 mb-5">
        <div class="col-md-3 col-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon d-none d-md-flex"><i class="bi bi-receipt"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat-total-invoices">0</h4>
                        <small class="text-uppercase small fw-bold">Total Invoices</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon d-none d-md-flex"><i class="bi bi-check-circle"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat-paid">0</h4>
                        <small class="text-uppercase small fw-bold">Total Paid</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon d-none d-md-flex"><i class="bi bi-clock-history"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat-pending">0</h4>
                        <small class="text-uppercase small fw-bold">Pending/Sent</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-3">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon d-none d-md-flex"><i class="bi bi-exclamation-triangle"></i></div>
                    <div>
                        <h4 class="mb-0 fw-bold" id="stat-overdue">0</h4>
                        <small class="text-uppercase small fw-bold">Overdue</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($filtered_customer): ?>
    <!-- Customer Info Bar -->
    <div class="row mb-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm bg-white rounded-4 overflow-hidden border-start border-primary border-5">
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="text-muted small fw-bold text-uppercase d-block mb-1">Customer Code</label>
                            <span class="fw-bold fs-5 text-primary"><?= safe_output($filtered_customer['customer_code']) ?></span>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted small fw-bold text-uppercase d-block mb-1">Name & Company</label>
                            <span class="fw-bold fs-6 d-block"><?= safe_output($filtered_customer['customer_name'] ?? '') ?></span>
                            <?php if (!empty($filtered_customer['company_name'])): ?>
                                <small class="text-muted text-truncate d-block" title="<?= safe_output($filtered_customer['company_name']) ?>">
                                    <?= safe_output($filtered_customer['company_name']) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2">
                            <label class="text-muted small fw-bold text-uppercase d-block mb-1">Tax ID (TIN)</label>
                            <span class="badge bg-light text-dark border fw-bold"><?= !empty($filtered_customer['tax_id']) ? safe_output($filtered_customer['tax_id']) : 'N/A' ?></span>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small fw-bold text-uppercase d-block mb-1">Contact Details</label>
                            <div class="small">
                                <?php if (!empty($filtered_customer['email'])): ?>
                                    <div class="mb-1"><i class="bi bi-envelope text-primary me-2"></i><?= safe_output($filtered_customer['email']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($filtered_customer['phone']) || !empty($filtered_customer['mobile'])): ?>
                                    <div><i class="bi bi-telephone text-success me-2"></i><?= safe_output($filtered_customer['phone'] ?: $filtered_customer['mobile']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Form Message -->
    <div id="form-message" class="mb-3"></div>

    <!-- Filters Card -->
    <div class="main-card mb-4 d-print-none">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <input type="hidden" name="payment_status" value="<?= safe_output($payment_filter) ?>">
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select class="form-select border-0 bg-light" name="status">
                        <option value="">All Statuses</option>
                        <option value="pending"  <?= $payment_filter == 'pending'  ? 'selected' : '' ?>>Pending</option>
                        <option value="reviewed" <?= $payment_filter == 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                        <option value="approved" <?= $payment_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="paid"     <?= $payment_filter == 'paid'     ? 'selected' : '' ?>>Paid</option>
                        <option value="partial"  <?= $payment_filter == 'partial'  ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="overdue"  <?= $payment_filter == 'overdue'  ? 'selected' : '' ?>>Overdue</option>
                        <option value="cancelled"<?= $payment_filter == 'cancelled'? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Customer</label>
                    <select class="form-select border-0 bg-light" name="customer">
                        <option value="">All Customers</option>
                        <?php
                        $cust_stmt = $pdo->query("SELECT customer_id, customer_name FROM customers ORDER BY customer_name ASC");
                        while ($c = $cust_stmt->fetch()): 
                        ?>
                            <option value="<?= $c['customer_id'] ?>" <?= $filtered_customer_id == $c['customer_id'] ? 'selected' : '' ?>>
                                <?= safe_output($c['customer_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" class="form-control border-0 bg-light" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" class="form-control border-0 bg-light" name="date_to" value="<?= $date_to ?>">
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
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printInvoicesList()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" id="filter_limit" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#invoicesTable').DataTable().page.len(this.value).draw();">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
        <div>
            <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill">
                <i class="bi bi-receipt-cutoff me-1"></i> Invoice Records
            </span>
        </div>
    </div>

    <!-- Invoices Table -->
    <div class="main-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="invoicesTable">
                    <thead class="bg-light text-uppercase small fw-bold text-muted">
                        <tr>
                            <th style="width:50px;" class="ps-4 py-3">S/NO</th>
                            <th class="ps-4 py-3">Invoice #</th>
                            <th class="py-3">Date</th>
                            <th class="py-3">Customer</th>
                            <?php if ($enable_projects): ?><th class="py-3">Project</th><?php endif; ?>
                            <th class="py-3" style="width:110px;">Type</th>
                            <th class="text-end py-3">Amount</th>
                            <th class="text-end py-3">Balance</th>
                            <th class="py-3">Status</th>
                            <th class="text-end pe-4 py-3 d-print-none">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- Report Footer Footer -->
    <div class="d-none d-print-block mt-5 pt-4 border-top">
        <div class="row">
            <div class="col-4 text-center">
                <div class="border-top mx-4 mt-4 pt-2 small text-muted">Prepared By</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top mx-4 mt-4 pt-2 small text-muted">Management Review</div>
            </div>
            <div class="col-4 text-center">
                <div class="border-top mx-4 mt-4 pt-2 small text-muted">Authorised Signature</div>
            </div>
        </div>
    </div>
</div>

<script>
var INV_IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;

$(document).ready(function() {
    // Log View
    logReportAction('Viewed Invoices List', 'User viewed the invoices management list');

    // Initialize DataTable
    var table = $('#invoicesTable').DataTable({
        dom: '<"row mb-3"<"col-md-6"l>>rtip',
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        serverSide: true, // Enable server-side processing
        processing: true,
        pageLength: 10, // Show 10 entries per page
        order: [[1, 'desc']],
        ajax: {
            // Using absolute .php path as fallback for systems without robust routing
            url: '<?= buildUrl('api/account/get_invoices.php') ?>',
            data: function(d) {
                const formData = new FormData($('#filterForm')[0]);
                for (let [key, value] of formData.entries()) {
                    d[key] = value;
                }
                console.log('DataTables request data:', d);
            },
            dataSrc: function(json) {
                console.log('API Response:', json);
                if (!json.success) {
                    console.error('API Error:', json.message);
                    Swal.fire('Error', json.message || 'Failed to load invoices', 'error');
                    return [];
                }
                if (json.stats) {
                    $('#stat-total-invoices').text(json.stats.total_invoices);
                    $('#stat-paid').text(json.stats.status_counts.paid || 0);
                    $('#stat-pending').text((json.stats.status_counts.pending || 0) + (json.stats.status_counts.sent || 0));
                    $('#stat-overdue').text(json.stats.status_counts.overdue || 0);
                    $('#stat-total-due').text(formatCurrency(json.stats.total_due));
                }
                return json.data || [];
            },
            error: function(xhr, error, thrown) {
                console.error('AJAX Error:', error, thrown);
                console.error('Response:', xhr.responseText);
                Swal.fire('Error', 'Failed to load invoices. Please check console for details.', 'error');
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'ps-4 text-center text-muted small fw-bold',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            { 
                data: 'invoice_number',
                className: 'ps-4',
                render: (data) => `<span class="fw-bold text-dark">${data}</span>`
            },
            { data: 'invoice_date' },
            { 
                data: 'customer_name',
                render: (data, t, row) => `<strong>${data}</strong>${row.company_name ? '<br><small class="text-muted">' + row.company_name + '</small>' : ''}`
            },
            <?php if ($enable_projects): ?>
            { 
                data: 'project_name',
                defaultContent: '-',
                render: (data) => data ? `<span class="text-primary fw-bold" style="font-size: 0.85rem;">${data}</span>` : '-'
            },
            <?php endif; ?>
            {
                data: 'invoice_type',
                render: (data) => {
                    const isService = data === 'Non-Inventory';
                    return `<span class="badge ${isService ? 'bg-info bg-opacity-10 text-info border border-info border-opacity-25' : 'bg-success bg-opacity-10 text-success border border-success border-opacity-25'}" style="font-size:0.65rem;">${data || 'Inventory'}</span>`;
                }
            },
            {
                data: 'grand_total',
                className: 'text-end',
                render: (data, t, row) => `<strong>${formatCurrency(data)}</strong> <small class="text-muted">${row.currency || ''}</small>`
            },
            { 
                data: 'balance_due',
                className: 'text-end',
                render: (data) => `<span class="${parseFloat(data) > 0 ? 'text-danger fw-bold' : 'text-success'}">${formatCurrency(data)}</span>`
            },
            { 
                data: 'display_status',
                render: (data) => {
                    const colors = {
                        'approved': 'text-success',
                        'paid':     'text-success',
                        'reviewed': 'text-info',
                        'partial':  'text-primary',
                        'overdue':  'text-danger',
                        'sent':     'text-info',
                        'pending':  'text-warning',
                        'draft':    'text-secondary',
                        'cancelled':'text-secondary'
                    };
                    return `<span class="${colors[data] || 'text-dark'} fw-bold text-uppercase" style="font-size: 0.85rem;">${data}</span>`;
                }
            },
            {
                data: null,
                className: 'text-end pe-4 d-print-none',
                orderable: false,
                render: function(data, type, row) {
                    let actions = `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item py-2" href="<?= getUrl('invoice_view') ?>?id=${row.invoice_id}"><i class="bi bi-eye text-primary me-2"></i> View Details</a></li>
                                ${row.status === 'pending'  ? '<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2 text-warning fw-bold" href="javascript:void(0)" onclick="reviewInvoice(' + row.invoice_id + ')"><i class="bi bi-search me-2"></i> Review</a></li>' : ''}
                                ${row.status === 'reviewed' ? '<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2 text-success fw-bold" href="javascript:void(0)" onclick="approveInvoice(' + row.invoice_id + ')"><i class="bi bi-check-circle me-2"></i> Approve</a></li>' : ''}

                    `;

                    if (['pending', 'reviewed'].includes(row.status)) {
                        actions += `<li><a class="dropdown-item py-2" href="<?= getUrl('invoice_edit') ?>?id=${row.invoice_id}" onclick="logReportAction('Initiated Invoice Edit', 'User clicked edit for invoice #${row.invoice_id}')"><i class="bi bi-pencil text-info me-2"></i> Edit Invoice</a></li>`;
                    }

                    actions += `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="printInvoice(${row.invoice_id})"><i class="bi bi-printer text-secondary me-2"></i> Print Invoice</a></li>`;

                    if (row.status === 'approved' && row.balance_due > 0) {
                        actions += `<li><hr class="dropdown-divider opacity-50"></li>`;
                        actions += `<li><a class="dropdown-item py-2 text-success fw-bold" href="<?= getUrl('payment_create') ?>?invoice=${row.invoice_id}"><i class="bi bi-cash-coin me-2"></i> Record Payment</a></li>`;
                    }

                    if (INV_IS_ADMIN) {
                        actions += `<li><hr class="dropdown-divider opacity-50"></li>`;
                        actions += `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteInvoice(${row.invoice_id})"><i class="bi bi-trash me-2"></i> Delete</a></li>`;
                    }

                    actions += `</ul></div>`;
                    return actions;
                }
            },
            {
                data: null,
                visible: false,
                className: 'd-none d-print-table-cell',
                render: function(data, type, row) { return ''; } // Placeholder for print stability if needed
            }
        ]
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        table.ajax.reload();
    });
});

function formatCurrency(v) {
    return new Intl.NumberFormat('en-TZ', { style: 'decimal', minimumFractionDigits: 2 }).format(v);
}

function printInvoicesList() {
    logReportAction('Printed Invoices List', 'User printed the invoices list');
    const oldTitle = document.title;
    document.title = "";
    window.print();
    document.title = oldTitle;
}

function printInvoice(id) {
    logReportAction('Printed Invoice', 'User printed invoice #' + id);
    window.open('<?= getUrl('invoice_print') ?>?id=' + id, '_blank');
}

function reviewInvoice(id) {
    Swal.fire({ title: 'Mark as Reviewed?', text: 'Status will change to Reviewed.', icon: 'question', showCancelButton: true, confirmButtonText: 'Review' }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post('<?= buildUrl('api/account/update_invoice_status.php') ?>', { invoice_id: id, status: 'reviewed' }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Reviewed', text: res.message, timer: 1500, showConfirmButton: false });
                $('#invoicesTable').DataTable().ajax.reload();
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function approveInvoice(id) {
    Swal.fire({ title: 'Approve this Invoice?', text: 'Status will change to Approved.', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve', confirmButtonColor: '#198754' }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post('<?= buildUrl('api/account/update_invoice_status.php') ?>', { invoice_id: id, status: 'approved' }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Approved', text: res.message, timer: 1500, showConfirmButton: false });
                $('#invoicesTable').DataTable().ajax.reload();
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function changeStatus(id, currentStatus) {
    const statuses = {
        'pending':   'Pending',
        'reviewed':  'Reviewed',
        'approved':  'Approved',
        'paid':      'Paid',
        'partial':   'Partial',
        'overdue':   'Overdue',
        'cancelled': 'Cancelled'
    };

    let options = '';
    for (let key in statuses) {
        options += `<option value="${key}" ${key === currentStatus ? 'selected' : ''}>${statuses[key]}</option>`;
    }

    Swal.fire({
        title: 'Change Invoice Status',
        html: `
            <select id="swal-status" class="form-select mt-3">
                ${options}
            </select>
        `,
        showCancelButton: true,
        confirmButtonText: 'Update Status',
        preConfirm: () => {
            return document.getElementById('swal-status').value;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('<?= buildUrl('api/account/update_invoice_status.php') ?>', {
                invoice_id: id,
                status: result.value
            }, function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: res.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                    logReportAction('Updated Invoice Status', 'User updated status of invoice #' + id + ' to ' + result.value);
                    $('#invoicesTable').DataTable().ajax.reload();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}


function deleteInvoice(id) {
    Swal.fire({
        title: 'Delete Invoice?',
        text: 'This invoice will be permanently deleted. This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('<?= buildUrl('api/account/delete_invoice.php') ?>', { invoice_id: id }, function(res) {
                if (res.success) {
                    logReportAction('Deleted Invoice', 'User deleted invoice #' + id);
                    Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, timer: 1500, showConfirmButton: false });
                    $('#invoicesTable').DataTable().ajax.reload();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function clearFilters() {
    $('#filterForm')[0].reset();
    $('#invoicesTable').DataTable().ajax.reload();
}

function exportInvoices() {
    logReportAction('Exported Invoices', 'User exported invoices list');
    const filters = $('#filterForm').serialize();
    window.location.href = '<?= buildUrl('api/account/export_invoices.php') ?>?' + filters;
}

function copyTable() {
    const table = document.getElementById('invoicesTable');
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    logReportAction('Copied Invoices List', 'User copied invoices list to clipboard');
    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1000, showConfirmButton: false });
}

function exportExcel() {
    const table = document.getElementById('invoicesTable');
    const rows = Array.from(table.querySelectorAll('tr'));
    const csvContent = rows.map(row => {
        const cols = Array.from(row.querySelectorAll('th, td')).slice(0, -1); // Exclude actions
        return cols.map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
    }).join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.setAttribute('download', 'Invoices.csv');
    document.body.appendChild(link);
    logReportAction('Exported Invoices Excel', 'User exported invoices list to Excel/CSV');
    link.click();
    document.body.removeChild(link);
}
</script>

<style>
/* Stats cards refined */
.stats-card h4, 
.stats-card small {
    text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table thead th {
    background-color: #f8fafc !important;
    border-bottom: 2px solid #e2e8f0;
    padding: 1.25rem 1rem;
    color: #475569;
}
.main-card { border-radius: 1.5rem; }
.dropdown-menu { 
    padding: 0.5rem;
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 15px;
}
.dropdown-item {
    border-radius: 8px;
    margin-bottom: 2px;
}
.dropdown-item:last-child { margin-bottom: 0; }

@media print {
    body { background: white !important; padding: 0 !important; }
    .invoice-dashboard { background: white !important; padding: 20px !important; }
    .stats-card, .custom-stat-card { 
        box-shadow: none !important; 
        border: 1px solid #d1e7dd !important;
        background-color: #f8fafc !important; /* Lighter for print */
    }
    .custom-stat-card h4, .custom-stat-card small { color: #000 !important; }
    .table-responsive { overflow: visible !important; }
    table { width: 100% !important; border-collapse: collapse !important; }
    th, td { border: 1px solid #dee2e6 !important; padding: 8px !important; }
    th { background-color: #f8f9fa !important; -webkit-print-color-adjust: exact; }
    .badge-premium { border: 1px solid #666 !important; color: #000 !important; background: transparent !important; }
    .d-print-none { display: none !important; }
    
    /* Hide DataTables clutter */
    .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate {
        display: none !important;
    }

    /* Print header styling */
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
}
</style>

<?php includeFooter(); ?>