<?php
// File: app/bms/purchase/rfq.php
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';
autoEnforcePermission('rfq');
includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View RFQs', 'User viewed the RFQ management list');

global $pdo;

$suppliers  = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// ?supplier=<id> — arriving from a supplier's RFQs tab; pre-selects the filter
// so the list opens showing only that supplier.
$rfq_supplier_filter = intval($_GET['supplier'] ?? 0);
// Scoped to the current user's assigned projects AND their warehouse grant
// (Phase 6 — pos_upgrade_plan.md); was a flat, fully-unscoped query before.
$warehouses = warehousesForSelect($pdo);

$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

$projects = [];
if ($enable_projects) {
    $_rfq_assigned = isAdmin() ? [] : array_values(array_filter(array_map('intval', $_SESSION['scope']['projects'] ?? [])));
    if (isAdmin()) {
        $projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status!='cancelled' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($_rfq_assigned)) {
        $_rfq_pph = implode(',', array_fill(0, count($_rfq_assigned), '?'));
        $_rfq_pstmt = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE status!='cancelled' AND project_id IN ($_rfq_pph) ORDER BY project_name");
        $_rfq_pstmt->execute($_rfq_assigned);
        $projects = $_rfq_pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Stats
$stats = ['total' => 0, 'pending' => 0, 'received' => 0, 'closed' => 0];
try {
    $r = $pdo->query("SELECT COUNT(*) as total,
        SUM(status IN ('draft','sent')) as pending,
        SUM(status='received') as received,
        SUM(status IN ('approved','partially')) as approved,
        SUM(status IN ('awarded','completed','cancelled')) as closed
        FROM rfq WHERE 1=1" . scopeFilterSqlNullable('project', 'rfq') . scopeFilterSqlNullable('warehouse', 'rfq'))->fetch(PDO::FETCH_ASSOC);
    if ($r) $stats = array_map('intval', $r);
} catch (Exception $e) {}

// Company info
$c_name  = getSetting('company_name', 'BMS');
$c_logo  = getSetting('company_logo', '');
$c_web   = getSetting('company_website', '');
$c_email = getSetting('company_email', '');
$c_tin   = getSetting('company_tin', '');
$c_vrn   = getSetting('company_vrn', '');

?>

<div class="rfq-page p-2 p-md-3" style="background:#fff;min-height:100vh;">

    <!-- ===== PRINT HEADER ===== -->
    <div class="print-header d-none d-print-block text-center mb-4">
       
        </h1>
        <div class="mt-2 text-center">
            <h2 style="color:#495057;font-weight:600;text-transform:uppercase;margin:5px 0;font-size:14pt;letter-spacing:2px;">
                REQUEST FOR QUOTATION LIST
            </h2>
            <p style="color:#444;margin:5px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">
                Generated At: <?= date('d M Y, h:i A') ?>
            </p>
        </div>
        <div style="border-bottom:3px solid #0d6efd;margin-top:15px;margin-bottom:25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div class="row g-2">
            <?php foreach([['Total RFQs',$stats['total']],['Pending / Sent',$stats['pending']],['Quotes Received',$stats['received']],['Closed / Cancelled',$stats['closed']]] as $sc): ?>
            <div class="col" style="flex:1 0 0%;">
                <div style="border:1px solid #dee2e6;padding:10px;text-align:center;">
                    <p style="color:#666;font-size:8pt;text-transform:uppercase;margin-bottom:2px;font-weight:600;"><?= $sc[0] ?></p>
                    <h4 style="color:#333;font-weight:800;margin:0;font-size:14pt;"><?= $sc[1] ?></h4>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ===== BREADCRUMB ===== -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item">Procurement</li>
            <li class="breadcrumb-item active">Request for Quotation</li>
        </ol>
    </nav>

    <!-- ===== PAGE HEADER ===== -->
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-file-earmark-text text-primary me-2"></i>Request for Quotation</h2>
            <p class="text-muted mb-0 small">Manage and track supplier quotation requests</p>
        </div>
        <a href="<?= getUrl('rfq_create') ?>" class="btn btn-primary shadow-sm">
            <i class="bi bi-plus-circle me-1"></i> Create RFQ
        </a>
    </div>

    <!-- ===== STAT CARDS ===== -->
    <div class="row g-3 mb-4 d-print-none">
        <?php
        $cards = [
            ['id'=>'stat-total',    'icon'=>'bi-file-earmark-text', 'val'=>$stats['total'],    'lbl'=>'Total RFQs'],
            ['id'=>'stat-pending',  'icon'=>'bi-hourglass-split',   'val'=>$stats['pending'],  'lbl'=>'Pending / Sent'],
            ['id'=>'stat-approved', 'icon'=>'bi-check2-circle',     'val'=>$stats['approved'], 'lbl'=>'Approved / Partial'],
            ['id'=>'stat-closed',   'icon'=>'bi-x-circle',          'val'=>$stats['closed'],   'lbl'=>'Completed / Closed'],
        ];
        foreach ($cards as $c): ?>
        <div class="col-6 col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100 overflow-hidden">
                <div class="card-body p-2 p-md-3 d-flex align-items-center gap-2">
                    <div class="stats-icon flex-shrink-0 d-none d-sm-flex"><i class="bi <?= $c['icon'] ?>"></i></div>
                    <div class="min-w-0 flex-grow-1">
                        <h4 class="mb-0 fw-bold" id="<?= $c['id'] ?>" style="font-size:clamp(1rem,3vw,1.3rem);line-height:1.2;"><?= $c['val'] ?></h4>
                        <small class="text-uppercase fw-bold text-muted" style="font-size:clamp(0.6rem,1.8vw,0.72rem);line-height:1.3;display:block;white-space:normal;word-break:break-word;"><?= $c['lbl'] ?></small>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ===== FILTERS ===== -->
    <div class="card border-0 shadow-sm mb-4 d-print-none">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filters &amp; Search</h6>
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Supplier</label>
                    <select class="form-select" name="supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['supplier_id'] ?>" <?= $rfq_supplier_filter == $s['supplier_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['supplier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($enable_projects): ?>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Project</label>
                    <select class="form-select" name="project">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $p): ?>
                        <option value="<?= $p['project_id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12 col-sm-6 col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Warehouse</label>
                    <select class="form-select" name="warehouse">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= $w['warehouse_id'] ?>"><?= htmlspecialchars($w['warehouse_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3 col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">From</label>
                    <input type="date" class="form-control" name="date_from">
                </div>
                <div class="col-6 col-sm-3 col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">To</label>
                    <input type="date" class="form-control" name="date_to">
                </div>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-search me-1"></i> Filter</button>
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="clearFilters()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== TOOLBAR ===== -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 d-print-none">
        <div class="btn-group" style="border:1px solid #dee2e6;border-radius:6px;overflow:hidden;">
            <button onclick="window.print()" class="btn btn-sm fw-medium px-3 py-2" style="background:#fff;color:#444;border:none;">
                <i class="bi bi-printer text-primary me-1"></i> Print
            </button>
            <div style="width:1px;background:#eee;height:20px;margin-top:7px;"></div>
            <button onclick="exportRFQ()" class="btn btn-sm fw-medium px-3 py-2" style="background:#fff;color:#444;border:none;">
                <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Export
            </button>
        </div>
        <div class="d-flex align-items-center bg-white px-3 py-1" style="border:1px solid #dee2e6;border-radius:6px;">
            <span class="small text-muted me-2">Show:</span>
            <select class="form-select form-select-sm border-0 p-0 fw-bold" style="width:55px;box-shadow:none;background:transparent;"
                onchange="$('#rfqTable').DataTable().page.len(this.value=='-1'?-1:parseInt(this.value)).draw()">
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="-1">All</option>
            </select>
        </div>
    </div>

    <!-- ===== DATA TABLE ===== -->
    <div class="card border-0 shadow-sm" id="rfqReportContainer">
        <div class="card-body p-0">
            <?php
            // Shared RFQ table — same code path as the RFQs tab in supplier_details.
            $tbl = [
                'id'         => 'rfqTable',
                'on_stats'   => 'function (s) {
                    $("#stat-total").text(s.total || 0);
                    $("#stat-pending").text(s.pending || 0);
                    $("#stat-approved").text(s.approved || 0);
                    $("#stat-closed").text(s.closed || 0);
                }',
                'filters_js' => 'function () {
                    return {
                        supplier:  $(\'select[name="supplier"]\').val(),
                        project:   $(\'select[name="project"]\').val(),
                        warehouse: $(\'select[name="warehouse"]\').val(),
                        status:    $(\'select[name="status"]\').val(),
                        date_from: $(\'input[name="date_from"]\').val(),
                        date_to:   $(\'input[name="date_to"]\').val()
                    };
                }',
            ];
            include ROOT_DIR . '/includes/tables/rfq_table.php';
            ?>
        </div>
    </div>
</div>

<style>
.custom-stat-card{background-color:#d1e7dd!important;border-color:#badbcc!important;transition:transform .2s;border-radius:12px;}
.custom-stat-card:hover{transform:translateY(-3px);}
.custom-stat-card h4,.custom-stat-card small,.custom-stat-card i{color:#0f5132!important;font-weight:600;}
.stats-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;background:rgba(15,81,50,.1);color:#0f5132!important;flex-shrink:0;}
.rfq-code{color:#0f5132!important;background:#d1e7dd!important;padding:2px 7px;border-radius:5px;font-weight:700;font-size:.82rem;white-space:nowrap;}
.table thead th{border-bottom:2px solid #e2e8f0;padding:.8rem .6rem;color:#475569;white-space:nowrap;}
.table tbody td{padding:.7rem .6rem;vertical-align:middle;}
.dropdown-menu{padding:.4rem;border:none;box-shadow:0 10px 30px rgba(0,0,0,.12);border-radius:12px;}
.dropdown-item{border-radius:7px;margin-bottom:2px;}

/* Mobile tweaks */
@media(max-width:575px){
    .rfq-page{padding:.5rem!important;}
    .table thead th,.table tbody td{padding:.5rem .4rem;font-size:.78rem;}
    .rfq-code{font-size:.75rem;padding:1px 5px;}
    .btn-group .btn{padding:.25rem .5rem;font-size:.78rem;}
}

/* ===== PRINT STYLES ===== */
@media print {
    .d-print-none,.card-header,.dropdown,.btn{display:none!important;}
    .rfq-page{background:#fff!important;padding:0!important;}
    .card{border:none!important;box-shadow:none!important;}
    .card-body,.table-responsive{overflow:visible!important;display:block!important;}
    body{padding:0!important;margin:0!important;background:#fff!important;}
   .container-fluid,.rfq-page{width:100%!important;max-width:none!important;padding:0!important;padding-bottom:0!important;}

    /* Canonical I/E Print margin — see i_e_print.md §1 (same value as
       ledger_report.php, grn.php, delivery_notes.php). The previous
       0.5in-all-round margin was a local, one-off value that didn't reserve
       enough bottom room for the shared footer once the local duplicate
       footer below is replaced with the canonical one. */
    @page { margin: 10mm 8mm 16mm 8mm; }

    /* Root cause of "page 1 is blank, the table starts on page 2": the
       global responsive.css rule `p, .card, section { page-break-inside:
       avoid }` treats #rfqReportContainer (the table's wrapping .card) as
       one unsplittable block — for a list many rows tall, that pushes the
       whole thing to a later page. Same fix as grn.php/delivery_notes.php. */
    #rfqReportContainer {
        page-break-inside: auto !important;
        break-inside: auto !important;
        overflow: visible !important;
    }

    #rfqTable{table-layout:auto!important;width:100%!important;border-collapse:collapse!important;}
    /* Font sizing mirrors ledger_report.php's #ledgerTable / grn.php's
       #grnTable exactly: header 7pt, body 7.5pt (forced onto the cell
       itself and everything nested inside it, so the Project badge/any
       Bootstrap .small text can't render at a different size). */
    #rfqTable th{white-space:nowrap!important;font-weight:bold!important;background:#f8f9fa!important;
        text-align:center!important;vertical-align:middle!important;
        -webkit-print-color-adjust:exact;border:1px solid #000!important;padding:8px 4px!important;font-size:7pt!important;}
    #rfqTable td{border:1px solid #ddd!important;padding:8px 4px!important;font-size:7.5pt!important;vertical-align:middle!important;}
    #rfqTable td *, #rfqTable th * { font-size: 7.5pt !important; max-width: 100% !important; white-space: normal !important; }
    #rfqTable tr{page-break-inside:avoid!important;}
    #rfqTable th:last-child,#rfqTable td:last-child{display:none!important;}

    /* Project "badge" printed as plain bold text — no pill/oval shape, same
       treatment already applied on grn.php/delivery_notes.php. */
    #rfqTable .rfq-project-badge {
        background: transparent !important;
        border: none !important;
        border-radius: 0 !important;
        padding: 0 !important;
        color: #000 !important;
        font-weight: 700 !important;
        font-size: 7.5pt !important;
    }

    .dataTables_length,.dataTables_info,.dataTables_paginate,.dataTables_filter{display:none!important;}
    .d-print-table-header{display:table-header-group!important;}
    .d-print-table-footer{display:table-footer-group!important;}

    /* The shared report footer (.print-footer, includes/print_footer_html.php)
       keeps its default fixed position from print_footer_css.php, so it
       repeats at the bottom of every printed page — same as every other
       report page. Keep ONE footer only: hide the global footer.php print
       footer (.bms-print-footer) that would otherwise render a duplicate
       here, and the page's own local one has been removed entirely. */
    .bms-print-footer { display: none !important; }

    .custom-stat-card{box-shadow:none!important;border:1px solid #d1e7dd!important;}
    .custom-stat-card h4,.custom-stat-card small{color:#000!important;}
}
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>

<script>
$(document).ready(function () {
    // The RFQ table itself is initialised by includes/tables/rfq_table.php.
    $('#filterForm').on('submit', function (e) { e.preventDefault(); BMSRfqTable.reload('rfqTable'); });
});


function clearFilters(){$('#filterForm')[0].reset();$('#rfqTable').DataTable().ajax.reload();}

function exportRFQ(){
    const t=document.getElementById('rfqTable');
    const rows=Array.from(t.querySelectorAll('tr'));
    const csv=rows.map(r=>Array.from(r.querySelectorAll('th,td')).slice(0,-1)
        .map(c=>`"${c.innerText.replace(/"/g,'""')}"`).join(',')).join('\n');
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8;'}));
    a.setAttribute('download','RFQ_List.csv');
    document.body.appendChild(a);a.click();document.body.removeChild(a);
}

}

</script>

<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ?>