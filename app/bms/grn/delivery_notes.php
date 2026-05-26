<?php
// File: app/bms/grn/delivery_notes.php
// scope-audit: skip — page shell only; DN data loaded via AJAX from api/get_delivery_notes_list.php which is scoped (scopeFilterSqlNullable via d.project_id)
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/workflow.php';

// Enforce permission (using GRN permissions as base)
autoEnforcePermission('grn');

// Include the header
includeHeader();

// Permission flags
$can_view_grn = isAdmin() || canView('grn');
$can_create_grn = isAdmin() || canCreate('grn');
$can_edit_grn = isAdmin() || canEdit('grn');
$can_delete_grn = isAdmin() || canDelete('grn');

// Three-approval capabilities use the 'dn' permission key
$dn_can_review  = canReview('dn');
$dn_can_approve = canApprove('dn');
$dn_is_admin    = isAdmin();

// Get filter parameters for dropdowns
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
$enable_projects = getSetting('enable_projects', 0);
$projects = [];
if ($enable_projects) {
    $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status != 'cancelled' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch stats for initial load
$stats_query = "
    SELECT
        COUNT(*) as count,
        SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_count,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status IN ('delivered','completed') THEN 1 ELSE 0 END) as completed_count
    FROM deliveries
";
$stats_stmt = $pdo->query($stats_query);
$initial_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>

<div class="container-fluid mt-4">
    <!-- Master Table for Print Space Management -->
    <table class="print-layout-table" style="width: 100%; border: none; border-collapse: collapse; background: transparent;">
        <tbody>
            <tr>
                <td style="border: none; padding: 0;">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none dn-list-sticky-nav">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('purchases') ?>">Purchases</a></li>
            <li class="breadcrumb-item active">Delivery Notes</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="fw-bold text-dark mb-1"><i class="bi bi-file-earmark-check text-primary"></i> Delivery Notes (DN)</h2>
                    <p class="text-muted mb-0">Track and manage supplier delivery notes and goods received</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($can_create_grn): ?>
                    <a href="<?= getUrl('dn_create') ?>" class="btn btn-success px-3 shadow-sm">
                        <i class="bi bi-box-arrow-in-down me-1"></i> Record DN <span class="d-none d-sm-inline">(Inbound)</span>
                    </a>
                    <a href="<?= getUrl('dn_outbound') ?>" class="btn btn-primary px-3 shadow-sm">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Create DN <span class="d-none d-sm-inline">(Outbound)</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-6 col-lg-3">
            <div class="card custom-stat-card border-0 shadow-sm overflow-hidden p-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1 overflow-hidden">
                            <h4 class="mb-0 fw-bold text-nowrap" id="stat-total-grns" style="font-size: 1.2rem; color: #0f5132 !important;"><?= $initial_stats['count'] ?></h4>
                            <p class="mb-0 text-uppercase small fw-bold text-truncate opacity-75" style="font-size: 0.7rem; color: #0f5132 !important;">Total Delivery Notes</p>
                        </div>
                        <div class="flex-shrink-0 ms-2">
                            <i class="bi bi-file-earmark-text text-success" style="font-size: 1.5rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card custom-stat-card border-0 shadow-sm overflow-hidden p-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1 overflow-hidden">
                            <h4 class="mb-0 fw-bold text-nowrap" id="stat-completed-grns" style="font-size: 1.2rem; color: #0f5132 !important;"><?= $initial_stats['completed_count'] ?></h4>
                            <p class="mb-0 text-uppercase small fw-bold text-truncate opacity-75" style="font-size: 0.7rem; color: #0f5132 !important;">Fully Received</p>
                        </div>
                        <div class="flex-shrink-0 ms-2">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 1.5rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card custom-stat-card border-0 shadow-sm overflow-hidden p-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1 overflow-hidden">
                            <h4 class="mb-0 fw-bold text-nowrap" id="stat-pending-grns" style="font-size: 1.2rem; color: #0f5132 !important;"><?= $initial_stats['pending_count'] ?></h4>
                            <p class="mb-0 text-uppercase small fw-bold text-truncate opacity-75" style="font-size: 0.7rem; color: #0f5132 !important;">Pending Processing</p>
                        </div>
                        <div class="flex-shrink-0 ms-2">
                            <i class="bi bi-clock-history text-warning" style="font-size: 1.5rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card custom-stat-card border-0 shadow-sm overflow-hidden p-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="flex-grow-1 overflow-hidden">
                            <h4 class="mb-0 fw-bold text-nowrap" id="stat-active-projects" style="font-size: 1.2rem; color: #0f5132 !important;"><?= count($projects) ?></h4>
                            <p class="mb-0 text-uppercase small fw-bold text-truncate opacity-75" style="font-size: 0.7rem; color: #0f5132 !important;">Linked Projects</p>
                        </div>
                        <div class="flex-shrink-0 ms-2">
                            <i class="bi bi-diagram-3 text-success" style="font-size: 1.5rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 d-print-none border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
            <h6 class="mb-0 fw-bold text-muted"><i class="bi bi-funnel me-1"></i> Filters & Parameters</h6>
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Supplier</label>
                    <select class="form-select select2-static" name="supplier" id="dn_filter_supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['supplier_id'] ?>"><?= safe_output($supplier['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Status</label>
                    <select class="form-select select2-static" name="status" id="dn_filter_status">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="approved">Approved</option>
                        <option value="delivered">Delivered</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Warehouse</label>
                    <select class="form-select select2-static" name="warehouse" id="dn_filter_warehouse">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?= $warehouse['warehouse_id'] ?>"><?= safe_output($warehouse['warehouse_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">From Date</label>
                    <input type="date" class="form-control" name="date_from">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">To Date</label>
                    <input type="date" class="form-control" name="date_to">
                </div>
                <div class="col-md-12 d-flex justify-content-end gap-2 mt-3">
                    <button type="submit" class="btn btn-primary px-4 shadow-sm">
                        <i class="bi bi-search me-1"></i> Search
                    </button>
                    <button type="button" class="btn btn-outline-secondary px-4 shadow-sm" onclick="clearDNFilters()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4 mt-2">
       
        
        <p class="text-dark mb-1 small text-uppercase text-center">
            <?php 
            $web_email = [];
            if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
            if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
            if (!empty($web_email)) echo implode(" | ", $web_email);
            ?>
        </p>

        <p class="text-dark mb-1 small text-uppercase text-center">
            <?php 
            $tin_vrn = [];
            if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
            if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
            if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
            ?>
        </p>

        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Delivery Notes Report</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated officially on <?= date('d M Y, H:i') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div class="row g-2 print-stats-row" style="display: flex !important; flex-direction: row !important; flex-wrap: nowrap !important;">
            <div class="col-4" style="flex: 1 1 0px !important;">
                <div class="print-stat-card" style="border: 1px solid #000; padding: 10px; text-align: center; height: 100%;">
                    <p style="color: #000; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 700;">Total Delivery Notes</p>
                    <h3 style="color: #000; font-weight: 800; margin: 0; font-size: 15pt;"><?= $initial_stats['count'] ?></h3>
                </div>
            </div>
            <div class="col-4" style="flex: 1 1 0px !important;">
                <div class="print-stat-card" style="border: 1px solid #000; padding: 10px; text-align: center; height: 100%;">
                    <p style="color: #000; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 700;">Fully Received</p>
                    <h3 style="color: #000; font-weight: 800; margin: 0; font-size: 15pt;"><?= $initial_stats['completed_count'] ?></h3>
                </div>
            </div>
            <div class="col-4" style="flex: 1 1 0px !important;">
                <div class="print-stat-card" style="border: 1px solid #000; padding: 10px; text-align: center; height: 100%;">
                    <p style="color: #000; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 700;">Pending Processing</p>
                    <h3 style="color: #000; font-weight: 800; margin: 0; font-size: 15pt;"><?= $initial_stats['pending_count'] ?></h3>
                </div>
            </div>
            <div class="col-4" style="flex: 1 1 0px !important;">
                <div class="print-stat-card" style="border: 1px solid #000; padding: 10px; text-align: center; height: 100%;">
                    <p style="color: #000; font-size: 8pt; text-transform: uppercase; margin-bottom: 5px; font-weight: 700;">Linked Projects</p>
                    <h3 style="color: #000; font-weight: 800; margin: 0; font-size: 15pt;"><?= count($projects) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 d-print-none">
        <div class="d-flex flex-wrap align-items-center gap-2 gap-md-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 10px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="logReportAction('Printed Delivery Notes List', 'User generated a printed report of the delivery notes'); window.print()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 10px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 50px; box-shadow: none; background: transparent;" onchange="dnTable.page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
        <div class="text-end">
            <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill">
                <i class="bi bi-file-earmark-check-fill me-1"></i> Delivery Records
            </span>
        </div>
    </div>

    <!-- Inbound / Outbound separation tabs -->
    <ul class="nav nav-tabs dn-type-tabs d-print-none" id="dnTypeTabs">
        <li class="nav-item">
            <button class="nav-link active" type="button" data-dntype="inbound">
                <i class="bi bi-box-arrow-in-down me-1"></i> Inbound — Received
                <span class="badge bg-primary ms-1" id="tabCountInbound">0</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" type="button" data-dntype="outbound">
                <i class="bi bi-box-arrow-up-right me-1"></i> Outbound — Sent
                <span class="badge bg-info ms-1" id="tabCountOutbound">0</span>
            </button>
        </li>
    </ul>

    <!-- Table Card -->
    <div class="card border-0 shadow-sm mb-4" style="border-top-left-radius:0;">
        <div class="card-header bg-white py-3 border-bottom d-print-none d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold" id="dnListHeading">Inbound Delivery Notes — Received</h5>
            <div class="btn-group shadow-sm d-none d-md-flex" role="group">
                <button type="button" class="btn btn-light btn-sm border" onclick="toggleViewMode('table')" id="tableViewBtn-toggle" title="Table View">
                    <i class="bi bi-table"></i>
                </button>
                <button type="button" class="btn btn-light btn-sm border" onclick="toggleViewMode('card')" id="cardViewBtn-toggle" title="Card View">
                    <i class="bi bi-grid"></i>
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <!-- Table View Container -->
            <div id="table-view-container" class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="dnTable" style="width:100%">
                    <thead class="bg-light sticky-header">
                        <tr>
                            <th style="width:50px;" class="ps-3">S/NO</th>
                            <th>DN Number</th>
                            <th style="width:90px;">Type</th>
                            <th>Date</th>
                            <th>Supplier / Sub-Contractor</th>
                            <?php if ($enable_projects): ?><th>Project</th><?php endif; ?>
                            <th>Items</th>
                            <th>Warehouse</th>
                            <th>Status</th>
                            <th style="width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Loaded via AJAX -->
                    </tbody>
                </table>
            </div>

            <!-- Card View Container -->
            <div id="card-view-container" class="row g-3 p-3 d-none">
                <!-- Populated via JS -->
            </div>
        </div>
    </div>
    </div>

                </td>
            </tr>
        </tbody>
        <tfoot class="d-none d-print-table-footer-group">
            <tr>
                <td style="border: none; padding: 0;">
                    <div class="print-footer-spacer" style="height: 60px;"></div>
                </td>
            </tr>
        </tfoot>
    </table>


   
</div>

<script>
// Three-approval capability flags (mirrored from PHP)
const DN_CAN_REVIEW  = <?= $dn_can_review  ? 'true' : 'false' ?>;
const DN_CAN_APPROVE = <?= $dn_can_approve ? 'true' : 'false' ?>;
const DN_IS_ADMIN    = <?= $dn_is_admin    ? 'true' : 'false' ?>;

let dnTable;
let currentDnType = 'inbound'; // active tab: 'inbound' or 'outbound'
const API_URL = "<?= getUrl('api/get_delivery_notes_list.php') ?>";

// View Mode Toggle Logic
function toggleViewMode(mode) {
    const isMobile = window.innerWidth <= 767;
    if (isMobile) mode = 'card';
    if (mode === 'card') {
        $('#table-view-container').addClass('d-none');
        $('#card-view-container').removeClass('d-none');
        $('#cardViewBtn-toggle').addClass('btn-primary text-white').removeClass('btn-light');
        $('#tableViewBtn-toggle').removeClass('btn-primary text-white').addClass('btn-light');
    } else {
        $('#table-view-container').removeClass('d-none');
        $('#card-view-container').addClass('d-none');
        $('#tableViewBtn-toggle').addClass('btn-primary text-white').removeClass('btn-light');
        $('#cardViewBtn-toggle').removeClass('btn-primary text-white').addClass('btn-light');
    }
    if (!isMobile) localStorage.setItem('dnView', mode);
}

// Desktop always opens as table view; mobile always opens as card view
function checkResponsiveView() {
    if (window.innerWidth <= 767) {
        toggleViewMode('card');
    } else {
        toggleViewMode(localStorage.getItem('dnView') || 'table');
    }
}

function renderCards(data) {
    const container = $('#card-view-container');
    container.empty();
    
    if (data.length === 0) {
        container.html('<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i> No records found</div>');
        return;
    }
    
    data.each(function(row) {
        const statusClass = getStatusClass(row.status);
        const cardHtml = `
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm border-0 hover-shadow transition-all" style="border-radius: 12px; border: 1px solid #eef2f6 !important;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <code class="small d-block mb-1">${safe_output(row.dn_number || row.delivery_number || 'No DN #')}</code>
                                <div class="mb-1">${dnTypeBadge(row.dn_type)}</div>
                                <h6 class="fw-bold mb-0">${safe_output(row.supplier_name)}</h6>
                                <small class="text-muted">${safe_output(row.company_name || '')}</small>
                            </div>
                            <span class="badge bg-${statusClass} small" style="font-size: 0.65rem;">${row.status.toUpperCase()}</span>
                        </div>
                        
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <small class="text-muted d-block small">Date</small>
                                <span class="small fw-medium text-dark">${formatDate(row.delivery_date)}</span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block small">Status</small>
                                <span class="badge bg-${getStatusClass(row.status)} small" style="font-size:0.65rem;">${row.status.toUpperCase()}</span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block small">Warehouse</small>
                                <span class="small text-dark text-truncate d-block" title="${safe_output(row.warehouse_name)}">${safe_output(row.warehouse_name)}</span>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block small">Items</small>
                                <span class="small text-dark">${row.total_items} items</span>
                            </div>
                            <?php if ($enable_projects): ?>
                            <div class="col-12 mt-2">
                                <small class="text-muted d-block small">Project</small>
                                <span class="badge bg-info-soft text-info border border-info small p-1 text-wrap d-inline-block w-100" style="white-space: normal; word-break: break-word;">${safe_output(row.project_name || 'N/A')}</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="dn-card-actions">
                            <a href="<?= getUrl('dn_view') ?>?id=${row.delivery_id}" class="btn btn-sm btn-outline-primary dn-card-btn" title="View"><i class="bi bi-eye"></i></a>
                            ${(row.status === 'pending' || row.status === 'reviewed' || DN_IS_ADMIN) ? `<a href="${dnEditUrl(row)}" class="btn btn-sm btn-outline-warning dn-card-btn" title="Edit"><i class="bi bi-pencil"></i></a>` : ''}
                            ${(row.status === 'pending' && DN_CAN_REVIEW) ? `<button class="btn btn-sm btn-outline-primary dn-card-btn" onclick="markReviewed(${row.delivery_id})" title="Mark Reviewed"><i class="bi bi-check2"></i></button>` : ''}
                            ${(row.status === 'reviewed' && DN_CAN_APPROVE) ? `<button class="btn btn-sm btn-outline-success dn-card-btn" onclick="approveDN(${row.delivery_id})" title="Approve"><i class="bi bi-check-circle"></i></button>` : ''}
                            <?php if ($can_delete_grn): ?>
                            ${(DN_IS_ADMIN || ['draft','pending','cancelled'].includes(row.status)) ? `<button class="btn btn-sm btn-outline-danger dn-card-btn" onclick="deleteDN(${row.delivery_id})" title="Delete"><i class="bi bi-trash"></i></button>` : ''}
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        `;
        container.append(cardHtml);
    });
}

function safe_output(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

$(document).ready(function() {
    checkResponsiveView();
    $(window).resize(checkResponsiveView);

    dnTable = $('#dnTable').DataTable({
        processing: true,
        serverSide: true,
        pageLength: 25,
        order: [[1, 'desc']],
        ajax: {
            url: API_URL,
            data: function(d) {
                return $.extend({}, d, {
                    supplier: $('select[name="supplier"]').val(),
                    dn_type: currentDnType,
                    status: $('select[name="status"]').val(),
                    warehouse: $('select[name="warehouse"]').val(),
                    date_from: $('input[name="date_from"]').val(),
                    date_to: $('input[name="date_to"]').val()
                });
            },
            dataSrc: function(json) {
                if (json.success) {
                    updateStats(json.stats);
                    if (json.type_counts) {
                        $('#tabCountInbound').text(json.type_counts.inbound || 0);
                        $('#tabCountOutbound').text(json.type_counts.outbound || 0);
                    }
                    return json.data;
                }
                return [];
            }
        },
        drawCallback: function(settings) {
            renderCards(this.api().rows({page:'current'}).data());
        },
        dom: 'rtp',
        columns: [
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'ps-3 text-muted small fw-bold text-center',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            {
                data: 'dn_number',
                render: function(data, type, row) {
                    const display = data || row.delivery_number || '';
                    const label   = display ? `<strong>${safe_output(display)}</strong>` : '<span class="text-muted fst-italic">No DN #</span>';
                    const sub     = data && row.delivery_number ? `<small class="text-muted">Ref: ${safe_output(row.delivery_number)}</small>` : `<small class="text-muted">ID: ${row.delivery_id}</small>`;
                    return label + '<br>' + sub;
                }
            },
            {
                data: 'dn_type',
                className: 'text-center',
                render: function(data) { return dnTypeBadge(data); }
            },
            {
                data: 'delivery_date',
                render: function(data) { return formatDate(data); }
            },
            {
                data: 'supplier_name',
                render: function(data, type, row) {
                    const kind = row.party_type === 'subcontractor'
                        ? '<small class="badge bg-light text-dark border">Sub-Contractor</small>'
                        : '<small class="badge bg-light text-dark border">Supplier</small>';
                    return `<span class="fw-bold">${safe_output(data)}</span> ${kind}${row.company_name ? `<br><small class="text-muted">${safe_output(row.company_name)}</small>` : ''}`;
                }
            },
            <?php if ($enable_projects): ?>
            {
                data: 'project_name',
                render: function(data) { 
                    return data ? `<span class="badge bg-info-soft text-info border border-info small p-1 text-wrap w-100" style="white-space: normal; word-break: break-word;">${safe_output(data)}</span>` : '<span class="text-muted small">N/A</span>'; 
                }
            },
            <?php endif; ?>
            { 
                data: 'total_items',
                render: function(data) { return `<span class="badge bg-secondary p-1">${data}</span> <small>items</small>`; }
            },
            { data: 'warehouse_name' },
            { 
                data: 'status',
                className: 'text-center',
                render: function(data) {
                    const cls = getStatusClass(data);
                    return `<span class="badge bg-${cls} small" style="font-size: 0.7rem; padding: 4px 8px;">${data.toUpperCase()}</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    const isPending  = (row.status === 'pending');
                    const isReviewed = (row.status === 'reviewed');
                    const isApproved = (row.status === 'approved');
                    const inWorkflow = (isPending || isReviewed);
                    const canEditNow = (inWorkflow || DN_IS_ADMIN);
                    const canDeleteNow = (DN_IS_ADMIN || ['draft','pending','cancelled'].includes(row.status));

                    let actions = `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu shadow-sm">
                                <li><a class="dropdown-item" href="<?= getUrl('dn_view') ?>?id=${row.delivery_id}"><i class="bi bi-eye text-info"></i> View Details</a></li>
                    `;

                    if (canEditNow) {
                        actions += `<li><a class="dropdown-item" href="${dnEditUrl(row)}"><i class="bi bi-pencil text-warning"></i> Edit Details</a></li>`;
                    }

                    // Parallel Review + Approve (one active, one disabled)
                    if (inWorkflow && DN_CAN_REVIEW) {
                        if (isPending) {
                            actions += `<li><a class="dropdown-item text-primary fw-bold" href="#" onclick="markReviewed(${row.delivery_id}); return false;"><i class="bi bi-check2"></i> Mark Reviewed</a></li>`;
                        } else {
                            actions += `<li><a class="dropdown-item text-muted disabled" href="#" tabindex="-1" aria-disabled="true" title="Already reviewed"><i class="bi bi-check2"></i> Mark Reviewed</a></li>`;
                        }
                    }
                    if (inWorkflow && DN_CAN_APPROVE) {
                        if (isReviewed) {
                            actions += `<li><a class="dropdown-item text-success fw-bold" href="#" onclick="approveDN(${row.delivery_id}); return false;"><i class="bi bi-check-circle"></i> Approve DN</a></li>`;
                        } else {
                            actions += `<li><a class="dropdown-item text-muted disabled" href="#" tabindex="-1" aria-disabled="true" title="Must be reviewed before approval"><i class="bi bi-check-circle"></i> Approve DN</a></li>`;
                        }
                    }

                    if (canDeleteNow) {
                        actions += `<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="#" onclick="deleteDN(${row.delivery_id}); return false;"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }

                    actions += `</ul></div>`;
                    return actions;
                }
            }
        ],
        language: {
            processing: '<div class="spinner-border spinner-border-sm text-primary"></div> Loading...',
            zeroRecords: 'No delivery notes found match your filters'
        }
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        loadDNs();
    });

    // Init Select2 on filter dropdowns
    $('.select2-static').each(function() {
        if ($(this).data('select2')) return;
        $(this).select2({
            theme: 'bootstrap-5',
            placeholder: $(this).find('option:first').text(),
            allowClear: true,
            width: '100%'
        });
    });

    // Inbound / Outbound tab switching — each tab is a separate list
    $('#dnTypeTabs .nav-link').on('click', function() {
        $('#dnTypeTabs .nav-link').removeClass('active');
        $(this).addClass('active');
        currentDnType = $(this).data('dntype');
        $('#dnListHeading').text(currentDnType === 'outbound'
            ? 'Outbound Delivery Notes — Sent'
            : 'Inbound Delivery Notes — Received');
        dnTable.ajax.reload();
    });
});

function loadDNs() {
    dnTable.ajax.reload();
}

function clearDNFilters() {
    $('#filterForm')[0].reset();
    $('.select2-static').trigger('change');
    loadDNs();
}

function updateStats(stats) {
    if (!stats) return;
    $('#stat-total-grns').text(stats.total_grns);
    $('#stat-completed-grns').text(stats.completed_count);
    $('#stat-pending-grns').text(stats.pending_count || stats.draft_count); 
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function getStatusClass(status) {
    switch(status.toLowerCase()) {
        case 'pending':   return 'warning';
        case 'reviewed':  return 'primary';
        case 'approved':  return 'info';
        case 'dispatched':return 'info';
        case 'delivered': return 'success';
        case 'completed': return 'success';
        case 'cancelled': return 'danger';
        default: return 'secondary';
    }
}

// Three-approval — dedicated APIs (replaces the legacy change_dn_status calls)
function markReviewed(id) {
    Swal.fire({
        title: 'Mark as Reviewed?',
        text: 'DN will move to Reviewed and become approvable.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#0d6efd', confirmButtonText: 'Yes, mark reviewed'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/review_dn.php') ?>', { delivery_id: id }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Reviewed!', text: res.message, timer: 1800, showConfirmButton: false });
                dnTable.ajax.reload();
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function approveDN(id) {
    Swal.fire({
        title: 'Approve Delivery Note?',
        text: 'Once approved, stock movements will fire.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#198754', confirmButtonText: 'Yes, approve'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/approve_dn.php') ?>', { delivery_id: id }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Approved!', text: res.message, timer: 2000, showConfirmButton: false });
                dnTable.ajax.reload();
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

// Inbound (Record) vs Outbound (Create) badge
function dnTypeBadge(t) {
    return t === 'outbound'
        ? '<span class="badge bg-info-subtle text-info border border-info" style="font-size:0.62rem;"><i class="bi bi-box-arrow-up-right"></i> OUTBOUND</span>'
        : '<span class="badge bg-primary-subtle text-primary border border-primary" style="font-size:0.62rem;"><i class="bi bi-box-arrow-in-down"></i> INBOUND</span>';
}

// Route edit to the correct form depending on the DN direction
function dnEditUrl(row) {
    const base = row.dn_type === 'outbound' ? '<?= getUrl("dn_outbound") ?>' : '<?= getUrl("dn_create") ?>';
    return base + '?edit=' + row.delivery_id;
}

function exportExcel() {
    logReportAction('Exported Delivery Notes', 'User downloaded the delivery notes list as CSV');
    window.location.href = `<?= getUrl('api/export_grns.php') ?>?` + $('#filterForm').serialize();
}

function deleteDN(id) {
    Swal.fire({
        title: 'Delete Delivery Note?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Deleting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData();
        fd.append('delivery_id', id);
        fetch('<?= getUrl('api/delete_dn.php') ?>', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message || 'Delivery note deleted.', confirmButtonColor: '#198754' })
                    .then(() => dnTable.ajax.reload(null, false));
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to delete delivery note.' });
            }
        })
        .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Network error while deleting.' }));
    });
}
function changeStatus(id, newStatus) {
    const cfg = {
        'review':   { title: 'Submit for Review?',  text: 'This DN will be sent for review.',            color: '#0d6efd', btn: 'Yes, Submit' },
        'approved': { title: 'Approve Delivery Note?', text: 'Once approved, stock will be updated.',      color: '#198754', btn: 'Yes, Approve' }
    };
    const m = cfg[newStatus];
    
    Swal.fire({
        title: m.title, text: m.text, icon: 'question',
        showCancelButton: true,
        confirmButtonColor: m.color,
        confirmButtonText: m.btn,
        cancelButtonText: 'Cancel'
    }).then(r => {
        if (r.isConfirmed) {
            Swal.fire({ title: 'Updating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.post('<?= getUrl("api/operations/change_dn_status") ?>', { delivery_id: id, status: newStatus }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Updated!', text: res.message, confirmButtonColor: '#198754' })
                        .then(() => dnTable.ajax.reload(null, false));
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            }, 'json');
        }
    });
}
</script>

<style>
    /* Modern UI - Delivery Notes Specific */
    .sticky-header th {
        position: sticky;
        top: 0;
        background-color: #f8f9fa !important;
        z-index: 1000;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
    }
    
    table.dataTable {
        width: 100% !important;
        margin: 0 !important;
        border-collapse: collapse !important;
        table-layout: fixed !important; /* Force fit to screen */
    }
    
    #dnTable th, #dnTable td {
        text-align: center !important;
        vertical-align: middle !important;
        padding: 12px 8px !important;
        border: 1px solid #dee2e6 !important;
        overflow-wrap: break-word !important;
        word-break: break-word !important;
        white-space: normal !important; 
    }

    /* Print Preview Consistency */
    @media print {
        @page { margin: 10mm; size: auto; }
        .d-print-none, .btn, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info { display: none !important; }
        table.dataTable { table-layout: fixed !important; }
        #dnTable th, #dnTable td {
            font-size: 8pt !important;
            padding: 5px !important;
            border: 1px solid #000 !important;
        }
        #dnTable th:last-child, #dnTable td:last-child { display: none !important; } /* Hide Actions */
    }

    .hover-shadow:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 15px rgba(0,0,0,0.1) !important;
    }
    .transition-all {
        transition: all 0.3s ease;
    }
    
    .custom-stat-card { 
        background-color: #d1e7dd !important; 
        border: 1px solid #badbcc !important; 
        border-radius: 15px !important; 
        transition: transform 0.2s, box-shadow 0.2s;
        min-height: auto;
        padding: 10px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .custom-stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important;
    }

    .dn-card-actions {
        display: flex;
        flex-wrap: nowrap;
        gap: 4px;
        padding-top: 0.65rem;
        border-top: 1px solid #dee2e6;
        margin-top: 0.5rem;
        background: #fff;
    }
    .dn-card-btn {
        flex: 1;
        min-width: 0;
        padding: 3px 4px !important;
        font-size: 0.72rem !important;
        text-align: center;
    }

    @media (max-width: 767px) {
        .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
        .dn-list-sticky-nav {
            position: sticky;
            top: 0;
            z-index: 1020;
            background: #fff;
            padding: 6px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
    }
</style>

<?php includeFooter(); ?>
