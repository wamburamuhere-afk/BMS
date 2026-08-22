<?php
// File: app/bms/grn/delivery_notes.php
// scope-audit: skip — Phase G complete; stats query scoped via scopeFilterSqlNullable('project','d'); suppliers/warehouses/projects dropdowns scoped inline; DN list loaded via AJAX (api/get_delivery_notes_list.php already scoped)
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/workflow.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';

// Enforce permission (using GRN permissions as base)
autoEnforcePermission('grn');

// Outbound (Sales) is only reachable via the Sales menu's ?type=outbound deep
// link — the Purchases entry point (no type param) must not offer it at all.
$is_outbound_view = (($_GET['type'] ?? '') === 'outbound');

// ?supplier=<id> — arriving from a supplier's Delivery Notes tab; pre-selects
// the filter so the list opens showing only that supplier.
$dn_supplier_filter = intval($_GET['supplier'] ?? 0);

// Include the header
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View delivery notes', 'User viewed the delivery notes management list');

// Permission flags
$can_view_grn = isAdmin() || canView('grn');
$can_create_grn = isAdmin() || canCreate('grn');
$can_edit_grn = isAdmin() || canEdit('grn');
$can_delete_grn = isAdmin() || canDelete('grn');

// Three-approval capabilities use the 'dn' permission key
$dn_can_review  = canReview('dn');
$dn_can_approve = canApprove('dn');
$dn_is_admin    = isAdmin();

// Get filter parameters for dropdowns — scoped by project for non-admins
$_dn_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
$enable_projects = getSetting('enable_projects', 0);

// Warehouses: shared helper — also respects the user's direct warehouse
// grant (Phase 6, pos_upgrade_plan.md), not just project membership.
$warehouses = warehousesForSelect($pdo);

if (isAdmin()) {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    $projects   = $enable_projects ? $pdo->query("SELECT project_id, project_name FROM projects WHERE status != 'cancelled' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC) : [];
} elseif (!empty($_dn_assigned)) {
    $_dn_ph = implode(',', array_fill(0, count($_dn_assigned), '?'));
    $_dn_sup = $pdo->prepare("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' AND (project_id IS NULL OR project_id IN ($_dn_ph)) ORDER BY supplier_name");
    $_dn_sup->execute($_dn_assigned);
    $suppliers = $_dn_sup->fetchAll(PDO::FETCH_ASSOC);
    $projects = [];
    if ($enable_projects) {
        $_dn_prj = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status != 'cancelled' AND project_id IN ($_dn_ph) ORDER BY project_name");
        $_dn_prj->execute($_dn_assigned);
        $projects = $_dn_prj->fetchAll(PDO::FETCH_ASSOC);
    }
} else {
    $suppliers  = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' AND project_id IS NULL ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    $projects   = [];
}

// Fetch stats for initial load — scoped via project_id on deliveries
$stats_query = "
    SELECT
        COUNT(*) as count,
        SUM(CASE WHEN d.status = 'pending'  THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN d.status = 'reviewed' THEN 1 ELSE 0 END) as reviewed_count,
        SUM(CASE WHEN d.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN d.status IN ('delivered','completed') THEN 1 ELSE 0 END) as completed_count
    FROM deliveries d
    WHERE 1=1
";
$stats_query .= scopeFilterSqlNullable('project', 'd');
$stats_query .= scopeFilterSqlNullable('warehouse', 'd');
$stats_stmt = $pdo->query($stats_query);
$initial_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>

<div class="container-fluid mt-4">
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
                    <?php if ($can_create_grn && !$is_outbound_view): ?>
                    <a href="<?= getUrl('dn_create') ?>" class="btn btn-success px-3 shadow-sm">
                        <i class="bi bi-box-arrow-in-down me-1"></i> Record DN <span class="d-none d-sm-inline">(Inbound)</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($can_create_grn && $is_outbound_view): ?>
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
                            <option value="<?= $supplier['supplier_id'] ?>" <?= $dn_supplier_filter == $supplier['supplier_id'] ? 'selected' : '' ?>><?= safe_output($supplier['supplier_name']) ?></option>
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

    <!-- Print Header — report title only. The company letterhead (logo + name)
         is already rendered once, globally, by header.php's renderPrintHeader()
         on every page; this block used to redundantly re-render it a second
         time, which is what produced the duplicate logo/company-name seen in
         print. Only the report-specific title/date belongs here, matching the
         pattern already used by Customer/Supplier/Sub-Contractor print. -->
    <div class="d-none d-print-block text-center mb-4 mt-2">
        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Delivery Notes Report</h2>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated officially on <?= date('d M Y, H:i') ?></p>
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
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 50px; box-shadow: none; background: transparent;" onchange="BMSDnTable.dt("dnTable").page.len(this.value).draw();">
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

    <!-- Inbound / Outbound separation tabs — each entry point (Purchases vs
         Sales menu) is fully self-contained: the Sales-side Outbound view
         never offers Inbound, and vice versa. -->
    <ul class="nav nav-tabs dn-type-tabs d-print-none" id="dnTypeTabs">
        <?php if (!$is_outbound_view): ?>
        <li class="nav-item">
            <button class="nav-link active" type="button" data-dntype="inbound">
                <i class="bi bi-box-arrow-in-down me-1"></i> Inbound — Received
                <span class="badge bg-primary ms-1" id="tabCountInbound">0</span>
            </button>
        </li>
        <?php else: ?>
        <li class="nav-item">
            <button class="nav-link active" type="button" data-dntype="outbound">
                <i class="bi bi-box-arrow-up-right me-1"></i> Outbound — Sent
                <span class="badge bg-info ms-1" id="tabCountOutbound">0</span>
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Table Card -->
    <div class="card border-0 shadow-sm mb-4" id="dnTableCard" style="border-top-left-radius:0;">
        <div class="card-header bg-white py-3 border-bottom d-print-none d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold" id="dnListHeading"><?= $is_outbound_view ? 'Outbound Delivery Notes — Sent' : 'Inbound Delivery Notes — Received' ?></h5>
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
            <div id="table-view-container">
                <?php
                // Shared Delivery Notes table — same code path as the Delivery
                // Notes tab in supplier_details.
                $tbl = [
                    'id'             => 'dnTable',
                    'card'           => '#card-view-container',
                    'party_label'    => $is_outbound_view ? 'Customer' : 'Supplier / Sub-Contractor',
                    'on_stats'       => 'updateStats',
                    'on_type_counts' => 'function (c) { $("#tabCountInbound").text(c.inbound || 0); $("#tabCountOutbound").text(c.outbound || 0); }',
                    'page_length'    => 25,
                    'filters_js'     => 'function () {
                        return {
                            supplier:  $(\'select[name="supplier"]\').val(),
                            dn_type:   currentDnType,
                            status:    $(\'select[name="status"]\').val(),
                            warehouse: $(\'select[name="warehouse"]\').val(),
                            date_from: $(\'input[name="date_from"]\').val(),
                            date_to:   $(\'input[name="date_to"]\').val()
                        };
                    }',
                ];
                include ROOT_DIR . '/includes/tables/delivery_notes_table.php';
                ?>
            </div>

            <!-- Card View Container -->
            <div id="card-view-container" class="row g-3 p-3 d-none">
                <!-- Populated via JS -->
            </div>
        </div>
    </div>
    </div>
</div>

<script>
// Three-approval capability flags (mirrored from PHP)
let currentDnType = '<?= $is_outbound_view ? 'outbound' : 'inbound' ?>'; // active tab: 'inbound' or 'outbound'

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

$(document).ready(function() {
    checkResponsiveView();
    $(window).resize(checkResponsiveView);

    // Deep link from the Sales menu (?type=outbound) — preselect the Outbound tab
    if (new URLSearchParams(location.search).get('type') === 'outbound') {
        currentDnType = 'outbound';
        $('#dnTypeTabs .nav-link').removeClass('active');
        $('#dnTypeTabs .nav-link[data-dntype="outbound"]').addClass('active');
        $('#dnListHeading').text('Outbound Delivery Notes — Sent');
    }


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
        BMSDnTable.reload("dnTable");
    });
});

function loadDNs() {
    BMSDnTable.reload("dnTable");
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

function exportExcel() {
    logReportAction('Exported Delivery Notes', 'User downloaded the delivery notes list as CSV');
    window.location.href = `<?= getUrl('api/export_grns.php') ?>?` + $('#filterForm').serialize();
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
                        .then(() => BMSDnTable.reload("dnTable"));
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
        /* Extra bottom clearance (16mm vs 10mm elsewhere) so the last row of
           content never sits under the shared fixed-position print footer --
           same margin already proven correct on Customer/Supplier/
           Sub-Contractor print. The previous uniform 10mm didn't leave enough
           room, so the footer overlapped/hid the bottom-most table row. */
        @page { margin: 10mm 8mm 16mm 8mm; size: auto; }
        .d-print-none, .btn, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info { display: none !important; }
        table.dataTable { table-layout: fixed !important; }

        /* Root cause of "columns half appear, cut off on the right in portrait":
           DataTables sets explicit inline pixel widths on th/td, calculated for
           the wide on-screen container -- those don't shrink for a narrower
           printed page, so the rightmost column(s) overflow off the page.
           Stripping them lets table-layout:fixed distribute the actually-visible
           columns evenly across the real print width, in both orientations. */
        #dnTable th, #dnTable td { width: auto !important; }

        /* Root cause of "a lot of blank pages": the global responsive.css rule
           `p, .card, section { page-break-inside: avoid !important }` applies to
           the Table Card (#dnTableCard) wrapping #dnTable. With even a modest
           number of rows the card is many times taller than one printed page, so
           an unbreakable "avoid" block that can never fit anywhere produces
           repeated blank pages while the browser hunts for room. A more specific
           override (id selector) beats the class rule and lets the table flow
           and break normally across pages, in both portrait and landscape. */
        #dnTableCard {
            page-break-inside: auto !important;
            break-inside: auto !important;
            overflow: visible !important;
        }
        #dnTable tr { page-break-inside: avoid !important; break-inside: avoid !important; }
        #dnTable thead { display: table-header-group; }

        #dnTable th, #dnTable td {
            padding: 5px !important;
            border: 1px solid #000 !important;
        }
        #dnTable th:last-child, #dnTable td:last-child { display: none !important; } /* Hide Actions */

        /* Font sizing mirrors ledger_report.php's #ledgerTable / grn.php's
           #grnTable exactly: header smaller than body (plain <th> is bold by
           default, already distinct from values without inflating its size). */
        #dnTable thead tr th {
            font-size: 7pt !important;
            line-height: 1.15 !important;
        }
        /* Base body size on the cell itself (covers a cell with no nested
           wrapper element, e.g. Warehouse), matching #ledgerTable/#grnTable's
           tbody td rule exactly. */
        #dnTable tbody td {
            font-size: 7.5pt !important;
        }
        /* Most cells wrap their value in Bootstrap's .small/<small>/badges
           (sized relative to their parent, or carrying their own inline
           font-size from the on-screen view) -- force every cell's nested
           content to the same 7.5pt too, so no column reads a different size
           from another (same fix already applied to grn.php). */
        #dnTable td *, #dnTable th * {
            max-width: 100% !important;
            white-space: normal !important;
            font-size: 7.5pt !important;
        }

        /* Project "badge" printed as plain bold text -- no pill/oval shape.
           #dnTable-qualified so this always wins over any generic .badge
           print rule elsewhere regardless of source order. */
        #dnTable .dn-project-badge {
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            padding: 0 !important;
            color: #000 !important;
            font-weight: 700 !important;
            font-size: 7.5pt !important;
        }
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
