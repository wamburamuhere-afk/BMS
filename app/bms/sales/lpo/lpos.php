<?php
// File: lpos.php
// scope-audit: skip — page shell only; LPO data loaded via AJAX from api/customer/get_lpos_list.php which is scoped (scopeFilterSqlNullable)
require_once __DIR__ . '/../../../../roots.php';
require_once __DIR__ . '/../../../../core/workflow.php';

// Enforce permission BEFORE any output
autoEnforcePermission('lpo');

// Three-approval workflow capabilities (PHP-side; mirrored to JS below)
$lpo_can_review  = canReview('lpo');
$lpo_can_approve = canApprove('lpo');
$lpo_is_admin    = isAdmin();

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View customer LPOs', 'User viewed the customer LPO management list');

// Get filter defaults
$customer_id = $_GET['customer'] ?? '';
$status = $_GET['status'] ?? '';

// Customers for filter dropdown — scoped to user's assigned projects for non-admins
global $pdo;
if (isAdmin()) {
    $customers = $pdo->query("SELECT customer_id, customer_name, company_name, customer_type FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $_lpo_assigned = array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
    if (!empty($_lpo_assigned)) {
        $_lpo_ph = implode(',', array_fill(0, count($_lpo_assigned), '?'));
        $cust_stmt = $pdo->prepare("SELECT customer_id, customer_name, company_name, customer_type FROM customers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_lpo_ph)) ORDER BY customer_name");
        $cust_stmt->execute($_lpo_assigned);
        $customers = $cust_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $customers = $pdo->query("SELECT customer_id, customer_name, company_name, customer_type FROM customers WHERE status = 'active' AND project_id IS NULL ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>

<div class="lpo-dashboard p-2 p-md-3" style="background: #ffffff; min-height: 100vh; width: 100%;">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 lpo-list-sticky-nav">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Customer LPOs</li>
        </ol>
    </nav>

    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-print-none flex-grow-1">
                    <h2 class="fw-bold mb-0 fs-4 fs-md-3 text-nowrap"><i class="bi bi-file-earmark-text text-success me-2"></i>Customer LPOs</h2>
                    <p class="text-muted mb-0 small d-none d-md-block">Local Purchase Orders received from customers</p>
                </div>
                <div class="d-flex align-items-center gap-2 d-print-none">
                    <a href="<?= getUrl('lpo_create') ?>" class="btn btn-primary btn-sm shadow-sm px-3 text-nowrap" style="border-radius: 6px;">
                        <i class="bi bi-plus-circle me-1"></i> New LPO
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2 p-md-3 overflow-hidden">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi bi-file-earmark-text"></i></div>
                    <div class="flex-grow-1 overflow-hidden text-center text-sm-start">
                        <h4 class="mb-0 fw-bold text-nowrap" id="stat-total-lpos" style="font-size: 1.1rem;">0</h4>
                        <small class="text-uppercase small fw-bold text-muted d-block text-truncate" style="font-size: 0.65rem;">Total LPOs</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2 p-md-3 overflow-hidden">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi bi-cash-stack"></i></div>
                    <div class="flex-grow-1 overflow-hidden text-center text-sm-start">
                        <h4 class="mb-0 fw-bold" id="stat-total-amount" style="font-size: 1.1rem; word-break: break-word;">TSh 0.00</h4>
                        <small class="text-uppercase small fw-bold text-muted d-block text-truncate" style="font-size: 0.65rem;">Total Value</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2 p-md-3 overflow-hidden">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi bi-clock-history"></i></div>
                    <div class="flex-grow-1 overflow-hidden text-center text-sm-start">
                        <h4 class="mb-0 fw-bold text-nowrap" id="stat-pending-lpos" style="font-size: 1.1rem;">0</h4>
                        <small class="text-uppercase small fw-bold text-muted d-block text-truncate" style="font-size: 0.65rem;">Pending</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card h-100 border-0 shadow-sm p-2 p-md-3 overflow-hidden">
                <div class="card-body p-0 d-flex align-items-center">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi bi-check-circle"></i></div>
                    <div class="flex-grow-1 overflow-hidden text-center text-sm-start">
                        <h4 class="mb-0 fw-bold" id="stat-approved-amount" style="font-size: 1.1rem; word-break: break-word;">TSh 0.00</h4>
                        <small class="text-uppercase small fw-bold text-muted d-block text-truncate" style="font-size: 0.65rem;">Approved</small>
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
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select class="form-select select2-static" id="lpo_filter_status" name="status">
                        <option value="" <?= !$status ? 'selected' : '' ?>>All Statuses</option>
                        <option value="pending" <?= $status == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="reviewed" <?= $status == 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                        <option value="approved" <?= $status == 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="partially_fulfilled" <?= $status == 'partially_fulfilled' ? 'selected' : '' ?>>Partially Fulfilled</option>
                        <option value="fulfilled" <?= $status == 'fulfilled' ? 'selected' : '' ?>>Fulfilled</option>
                        <option value="cancelled" <?= $status == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Customer</label>
                    <select class="form-select select2-static" id="lpo_filter_customer" name="customer">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $c):
                            $cname = ($c['customer_type'] === 'business' && !empty($c['company_name'])) ? $c['company_name'] : $c['customer_name'];
                        ?>
                            <option value="<?= $c['customer_id'] ?>" <?= $customer_id == $c['customer_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" class="form-control" name="date_from">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" class="form-control" name="date_to">
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

    <!-- Views Container -->
    <div id="tableView" class="view-section">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom d-flex align-items-center py-2 px-3 d-print-none">
                <span class="fw-bold text-muted small">LPO Records</span>
                <div class="btn-group shadow-sm ms-auto d-none d-md-flex" role="group">
                    <button type="button" class="btn btn-primary btn-sm text-white" id="btn-table-view" onclick="toggleView('table')" title="Table View">
                        <i class="bi bi-table"></i>
                    </button>
                    <button type="button" class="btn btn-light btn-sm border" id="btn-card-view" onclick="toggleView('card')" title="Card View">
                        <i class="bi bi-grid-3x3-gap"></i>
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="lposTable" style="width: 100%;">
                        <thead class="bg-light text-uppercase small fw-bold">
                            <tr>
                                <th style="width:50px;" class="ps-4">S/NO</th>
                                <th class="ps-4">LPO #</th>
                                <th>Customer</th>
                                <th>Issue Date</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                                <th class="text-end pe-4 d-print-none">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="border-top-0">
                            <!-- DataTables content -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Card View Container (Hidden by default) -->
    <div id="cardView" class="view-section d-none">
        <div class="row g-3" id="lposCards"></div>
    </div>
</div> <!-- dashboard end -->

<script>
const LPO_CAN_REVIEW  = <?= $lpo_can_review  ? 'true' : 'false' ?>;
const LPO_CAN_APPROVE = <?= $lpo_can_approve ? 'true' : 'false' ?>;
const LPO_IS_ADMIN    = <?= $lpo_is_admin    ? 'true' : 'false' ?>;

$(document).ready(function() {
    logReportAction('Viewed Customer LPOs List', 'User viewed the customer LPO management list');

    const table = $('#lposTable').DataTable({
        dom: 'rtip',
        serverSide: false,
        ajax: {
            url: '<?= getUrl('api/get_lpos_list') ?>',
            data: function(d) {
                d.status = $('select[name="status"]').val();
                d.customer_id = $('select[name="customer"]').val();
                d.date_from = $('input[name="date_from"]').val();
                d.date_to = $('input[name="date_to"]').val();
            },
            dataSrc: function(json) {
                if (json.stats) {
                    $('#stat-total-lpos').text(json.stats.total_lpos);
                    $('#stat-total-amount').text(formatCurrency(json.stats.total_amount));
                    $('#stat-pending-lpos').text(json.stats.pending_count);
                    $('#stat-approved-amount').text(formatCurrency(json.stats.approved_amount || 0));
                }
                return json.data;
            }
        },
        columns: [
            {
                data: null, orderable: false, searchable: false, width: '50px',
                className: 'ps-4 text-center text-muted small fw-bold',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            {
                data: 'lpo_number', className: 'ps-4',
                render: (data) => `<span class="fw-bold text-dark custom-code">${data}</span>`
            },
            { data: 'customer_display_name', defaultContent: '-' },
            { data: 'issue_date' },
            {
                data: 'amount', className: 'text-end',
                render: (data, t, row) => `<strong>${formatCurrency(data)}</strong> <small class="text-muted">${row.currency}</small>`
            },
            {
                data: 'status',
                render: (data) => {
                    const colors = {
                        'pending': 'text-warning', 'reviewed': 'text-primary', 'approved': 'text-info',
                        'open': 'text-info', 'partially_fulfilled': 'text-primary', 'fulfilled': 'text-success',
                        'cancelled': 'text-danger'
                    };
                    const colorClass = colors[data] || 'text-dark';
                    return `<span class="${colorClass} text-uppercase fw-bold" style="font-size:0.85rem;letter-spacing:0.5px;">${data.replace(/_/g, ' ')}</span>`;
                }
            },
            {
                data: null, className: 'text-end pe-4 d-print-none',
                render: function(data, type, row) {
                    const isPending  = row.status === 'pending';
                    const isReviewed = row.status === 'reviewed';
                    const isApproved = row.status !== 'pending' && row.status !== 'reviewed';
                    const canEditNow = !isApproved || LPO_IS_ADMIN;
                    return `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><a class="dropdown-item py-2" href="<?= getUrl('lpo_view') ?>?id=${row.lpo_id}"><i class="bi bi-eye text-primary me-2"></i> View Details</a></li>
                                ${(isPending && LPO_CAN_REVIEW) ? `<li><a class="dropdown-item py-2 text-primary fw-bold" href="#" onclick="reviewLpo(${row.lpo_id}, '${row.lpo_number}')"><i class="bi bi-check2 me-2"></i> Mark Reviewed</a></li>` : ''}
                                ${(isReviewed && LPO_CAN_APPROVE) ? `<li><a class="dropdown-item py-2 text-success fw-bold" href="#" onclick="approveLpo(${row.lpo_id}, '${row.lpo_number}')"><i class="bi bi-check-circle me-2"></i> Approve LPO</a></li>` : ''}
                                ${canEditNow ? `<li><a class="dropdown-item py-2" href="<?= getUrl('lpo_create') ?>?edit=${row.lpo_id}"><i class="bi bi-pencil text-info me-2"></i> Edit</a></li>` : ''}
                                <li><a class="dropdown-item py-2" href="<?= getUrl('print_lpo') ?>?id=${row.lpo_id}" target="_blank"><i class="bi bi-printer text-dark me-2"></i> Print</a></li>
                            </ul>
                        </div>
                    `;
                }
            }
        ],
        order: [[3, 'desc']],
        drawCallback: function(settings) {
            const api = this.api();
            const data = api.rows({ page: 'current' }).data();
            const cardContainer = $('#lposCards');
            cardContainer.empty();
            data.each(function(row) {
                const statusColors = {
                    'pending': 'bg-warning', 'reviewed': 'bg-primary', 'approved': 'bg-info',
                    'open': 'bg-info', 'partially_fulfilled': 'bg-primary', 'fulfilled': 'bg-success',
                    'cancelled': 'bg-danger'
                };
                const statusColor = statusColors[row.status] || 'bg-dark';
                const card = `
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="card h-100 shadow-sm border-light">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <span class="custom-code small mb-2 d-inline-block">${row.lpo_number}</span>
                                        <h6 class="fw-bold mb-0">${row.customer_display_name || '-'}</h6>
                                    </div>
                                    <span class="badge ${statusColor} text-uppercase small">${row.status.replace(/_/g, ' ')}</span>
                                </div>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Date:</span><span class="fw-medium">${row.issue_date}</span></div>
                                    <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Amount:</span><span class="fw-bold text-dark">${formatCurrency(row.amount)} ${row.currency}</span></div>
                                </div>
                                <div style="display:flex;flex-wrap:nowrap;gap:4px;padding-top:0.65rem;border-top:1px solid #dee2e6;margin-top:0.5rem;">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= getUrl('lpo_view') ?>?id=${row.lpo_id}" title="View" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-eye"></i></a>
                                    ${(row.status === 'pending' && LPO_CAN_REVIEW) ? `<button class="btn btn-sm btn-outline-primary" onclick="reviewLpo(${row.lpo_id}, '${row.lpo_number}')" title="Mark Reviewed" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-check2"></i></button>` : ''}
                                    ${(row.status === 'reviewed' && LPO_CAN_APPROVE) ? `<button class="btn btn-sm btn-outline-success" onclick="approveLpo(${row.lpo_id}, '${row.lpo_number}')" title="Approve" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-check-circle"></i></button>` : ''}
                                    <a class="btn btn-sm btn-outline-warning" href="<?= getUrl('lpo_create') ?>?edit=${row.lpo_id}" title="Edit" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-pencil"></i></a>
                                    <a class="btn btn-sm btn-outline-dark" href="<?= getUrl('print_lpo') ?>?id=${row.lpo_id}" target="_blank" title="Print" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-printer"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                cardContainer.append(card);
            });
        }
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        table.ajax.reload();
    });

    const view = (window.innerWidth < 768) ? 'card' : 'table';
    toggleView(view);

    $('.select2-static').each(function() {
        if ($(this).data('select2')) return;
        $(this).select2({ theme: 'bootstrap-5', placeholder: $(this).find('option:first').text(), allowClear: true, width: '100%' });
    });
});

function toggleView(viewType) {
    const tableView = $('#tableView');
    const cardView = $('#cardView');
    if (viewType === 'table') {
        tableView.removeClass('d-none'); cardView.addClass('d-none');
        $('#btn-table-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-card-view').removeClass('btn-primary text-white').addClass('btn-light');
    } else {
        tableView.addClass('d-none'); cardView.removeClass('d-none');
        $('#btn-card-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-table-view').removeClass('btn-primary text-white').addClass('btn-light');
    }
}

function formatCurrency(v) {
    return new Intl.NumberFormat('en-TZ', { style: 'decimal', minimumFractionDigits: 2 }).format(v);
}

function clearFilters() {
    $('#filterForm')[0].reset();
    $('.select2-static').val('').trigger('change');
    $('#lposTable').DataTable().ajax.reload();
}

function reviewLpo(id, lpoNumber) {
    Swal.fire({
        title: 'Mark as Reviewed?',
        text: 'LPO ' + lpoNumber + ' will move to "Reviewed" and become approvable.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#0d6efd', confirmButtonText: 'Yes, mark reviewed', cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('<?= getUrl('api/review_lpo') ?>', { lpo_id: id }, function(response) {
            if (response.success) {
                Swal.fire({ icon: 'success', title: 'Reviewed!', text: response.message, timer: 1800, showConfirmButton: false });
                $('#lposTable').DataTable().ajax.reload();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to mark reviewed' });
            }
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Communication error. Please try again.' }));
    });
}

function approveLpo(id, lpoNumber) {
    Swal.fire({
        title: 'Approve LPO?',
        text: 'Are you sure you want to approve LPO ' + lpoNumber + '?',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#198754', confirmButtonText: 'Yes, approve it!', cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.post('<?= getUrl('api/approve_lpo') ?>', { lpo_id: id }, function(response) {
            if (response.success) {
                Swal.fire({ icon: 'success', title: 'Approved!', text: 'LPO has been approved.', timer: 2000, showConfirmButton: false });
                $('#lposTable').DataTable().ajax.reload();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to approve LPO' });
            }
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Communication error. Please try again.' }));
    });
}
</script>

<style>
.custom-stat-card { background-color: #d1e7dd !important; border-color: #badbcc !important; transition: transform 0.2s; border-radius: 12px; }
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, .custom-stat-card small, .custom-stat-card i { color: #0f5132 !important; font-weight: 600; }
.stats-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-right: 1.25rem; background: rgba(15, 81, 50, 0.1); color: #0f5132 !important; }
.custom-code { color: #0f5132 !important; background-color: #d1e7dd !important; padding: 2px 6px; border-radius: 6px; font-weight: bold; }
.table thead th { background-color: #f8fafc !important; border-bottom: 2px solid #e2e8f0; padding: 0.75rem 0.5rem; color: #475569; font-size: 0.85rem; }
.table td { padding: 0.6rem 0.5rem !important; font-size: 0.82rem; }
.lpo-dashboard { width: 100% !important; max-width: 100% !important; overflow-x: hidden !important; padding-left: 20px !important; padding-right: 20px !important; }
@media (max-width: 576px) { .lpo-dashboard { padding-left: 10px !important; padding-right: 10px !important; } }
@media (max-width: 767px) { .lpo-list-sticky-nav { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 6px 0; } }
.dropdown-menu { padding: 0.5rem; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 15px; }
.dropdown-item { border-radius: 8px; margin-bottom: 2px; }
</style>

<?php includeFooter(); ?>
