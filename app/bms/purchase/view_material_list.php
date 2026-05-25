<?php
// scope-audit: skip — NIP material list view; scope by project_id pending Phase G-2
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('nip_materials');
includeHeader();

$c_name = getSetting('company_name', 'BMS');
$c_logo = getSetting('company_logo', '');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo "<script>window.location.href='" . getUrl('nip_materials') . "';</script>";
    exit();
}

// Load list header
$header = null;
try {
    $hStmt = $pdo->prepare("
        SELECT ml.id, ml.name,
               COALESCE(ml.list_no,
                   CONCAT('ML-', DATE_FORMAT(ml.created_at,'%Y%m%d'), '-', LPAD(ml.id,4,'0'))
               ) AS list_no,
               ml.created_at,
               COALESCE(p.project_name,'')   AS project_name,
               COALESCE(w.warehouse_name,'') AS warehouse_name
        FROM nip_material_lists ml
        LEFT JOIN projects   p ON ml.project_id   = p.project_id
        LEFT JOIN warehouses w ON ml.warehouse_id = w.warehouse_id
        WHERE ml.id = ?
    ");
    $hStmt->execute([$id]);
    $header = $hStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

if (!$header) {
    echo "<script>window.location.href='" . getUrl('nip_materials') . "';</script>";
    exit();
}

// Aggregated materials (main table)
$materials = [];
try {
    $mStmt = $pdo->prepare("
        SELECT
            cp.product_id  AS component_product_id,
            cp.product_name AS description,
            MAX(pac.unit)  AS unit,
            cp.sku         AS item_no,
            ROUND(SUM(pac.qty_per_unit * mln.quantity), 4) AS quantity
        FROM nip_material_list_nips mln
        JOIN product_assembly_components pac ON pac.parent_product_id = mln.nip_product_id
        JOIN products cp ON pac.component_product_id = cp.product_id
        WHERE mln.material_list_id = ?
        GROUP BY cp.product_id, cp.product_name, cp.sku
        ORDER BY cp.product_name ASC
    ");
    $mStmt->execute([$id]);
    $materials = $mStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Per-NIP breakdown (More Details modal) — grouped by NIP
$details_by_nip = [];
try {
    $dStmt = $pdo->prepare("
        SELECT
            np.product_id   AS nip_product_id,
            np.product_name AS nip_name,
            np.contract_item_no AS nip_item_code,
            np.unit         AS nip_unit,
            mln.quantity    AS nip_qty,
            cp.product_name AS component_name,
            cp.sku          AS item_code,
            pac.unit        AS component_unit,
            pac.qty_per_unit,
            ROUND(pac.qty_per_unit * mln.quantity, 4) AS total_qty
        FROM nip_material_list_nips mln
        JOIN products np ON mln.nip_product_id = np.product_id
        JOIN product_assembly_components pac ON pac.parent_product_id = mln.nip_product_id
        JOIN products cp ON pac.component_product_id = cp.product_id
        WHERE mln.material_list_id = ?
        ORDER BY np.product_name ASC, cp.product_name ASC
    ");
    $dStmt->execute([$id]);
    foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nip_id = $r['nip_product_id'];
        if (!isset($details_by_nip[$nip_id])) {
            $details_by_nip[$nip_id] = [
                'nip_name'   => $r['nip_name'],
                'nip_item_code' => $r['nip_item_code'],
                'nip_unit'   => $r['nip_unit'],
                'nip_qty'    => $r['nip_qty'],
                'components' => []
            ];
        }
        $details_by_nip[$nip_id]['components'][] = [
            'component_name' => $r['component_name'],
            'item_code'      => $r['item_code'],
            'component_unit' => $r['component_unit'],
            'qty_per_unit'   => $r['qty_per_unit'],
            'total_qty'      => $r['total_qty']
        ];
    }
} catch (Exception $e) {}

$export_user = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$export_role = ucwords($_SESSION['user_role'] ?? 'Staff');
?>
<style>
.ml-view-header-card { background:#fff; border-left:4px solid #0d6efd; }
.ml-view-header-card .text-muted { color:#555!important; font-weight:600; }
.ml-view-header-card .fw-bold { color:#111!important; }
#viewMatTable thead th {
    background:#fff; color:#111; border-bottom:2px solid #dee2e6;
    font-size:.78rem; text-transform:uppercase; font-weight:700;
}
#detailsTable thead th {
    background:#0d6efd; color:#fff; font-size:.78rem; text-transform:uppercase;
}
#detailsTable tbody tr.nip-group-first { border-top:2px solid #dee2e6; }
#detailsTable td.nip-cell { background:#f0f4ff; font-weight:600; vertical-align:middle; }
@page {
    margin: 0.5cm 1cm 1.5cm 1cm;
}
@media print {
    .d-print-none { display:none!important; }
    .card { border:none!important; box-shadow:none!important; }
    .table { font-size:9px; }
    th { white-space:nowrap!important; font-size:9px!important; }
    td { font-size:9px!important; }
    thead { display:table-header-group; }
    .container-fluid { padding:0!important; }
    .print-footer {
        display:flex!important; flex-direction:column; justify-content:center;
        align-items:center; text-align:center;
        position:fixed; bottom:0; left:0; right:0;
        height:1cm; background:#fff;
        border-top:1px solid #ccc; font-size:8px; z-index:9999;
        -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
    body { padding-bottom:1.2cm; }
}
</style>

<div class="container-fluid mt-4">

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('purchases') ?>">Procurement</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('nip_materials') ?>">Materials</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($header['list_no']) ?></li>
        </ol>
    </nav>

    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">
       
        <h4 class="fw-bold text-dark text-uppercase">Materials List — <?= htmlspecialchars($header['list_no']) ?></h4>
        <p class="text-muted small">Date: <?= date('d M, Y') ?></p>
        <hr>
    </div>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-start mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1"><i class="bi bi-eye text-primary me-2"></i>View Materials</h2>
            <p class="text-muted mb-0 small"><?= htmlspecialchars($header['name']) ?></p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-info fw-bold shadow-sm" onclick="mlMoreDetails()">
                <i class="bi bi-layout-text-window me-1"></i> More Details
            </button>
            <a href="<?= getUrl('nip_materials') ?>" class="btn btn-light border fw-bold">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <!-- List Info Card -->
    <div class="card shadow-sm border-0 mb-4 ml-view-header-card">
        <div class="card-body py-3">
            <div class="row g-3">
                <div class="col-sm-6 col-md-3">
                    <div class="text-muted small">Materials List No</div>
                    <div class="fw-bold">
                        <span class="badge bg-primary" style="font-size:.85rem;letter-spacing:.5px;">
                            <?= htmlspecialchars($header['list_no']) ?>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="text-muted small">List Name</div>
                    <div class="fw-bold text-dark"><?= htmlspecialchars($header['name']) ?></div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="text-muted small">Project</div>
                    <div class="fw-bold"><?= htmlspecialchars($header['project_name']) ?></div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="text-muted small">Warehouse</div>
                    <div class="fw-bold"><?= htmlspecialchars($header['warehouse_name']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Table action bar -->
    <div class="d-flex align-items-center gap-2 mb-3 d-print-none">
        <div class="d-flex align-items-center gap-1">
            <label class="mb-0 small text-muted">Show</label>
            <select id="viewMatLength" class="form-select form-select-sm" style="width:auto;">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="-1">All</option>
            </select>
            <span class="small text-muted">entries</span>
        </div>
        <button onclick="window.print()" class="btn btn-light border btn-sm">
            <i class="bi bi-printer me-1"></i> Print
        </button>
        <button onclick="mlExportPdf()" class="btn btn-light border btn-sm">
            <i class="bi bi-download me-1"></i> Export PDF
        </button>
    </div>

    <!-- Aggregated Materials Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="viewMatTable">
                    <thead>
                        <tr>
                            <th class="text-center ps-3" style="width:6%">S/NO</th>
                            <th style="width:38%">Description</th>
                            <th class="text-center" style="width:12%">Unit</th>
                            <th class="text-center" style="width:20%">Item No (SKU)</th>
                            <th class="text-center" style="width:14%">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($materials)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">
                            <i class="bi bi-box-seam" style="font-size:3rem;opacity:.25;"></i>
                            <p class="mt-3">No materials found. Ensure the NIPs in this list have components defined.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($materials as $i => $m): ?>
                    <tr>
                        <td class="text-center ps-3 text-muted fw-bold"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($m['description']) ?></div>
                        </td>
                        <td class="text-center text-muted"><?= htmlspecialchars($m['unit'] ?? '') ?: '&mdash;' ?></td>
                        <td class="text-center">
                            <?php if (!empty($m['item_no'])): ?>
                            <span class="badge bg-light text-dark border small"><?= htmlspecialchars($m['item_no']) ?></span>
                            <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center fw-bold text-primary"><?= number_format((float)$m['quantity'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Print footer — shown only when printing -->
    <div class="print-footer d-none">
        <p style="margin:0 0 1px;color:#888;font-size:8px;">
            This document was Printed by <strong style="color:#212529;"><?= htmlspecialchars($export_user) ?></strong>
            on <strong style="color:#212529;"><?= date('d M, Y \a\t H:i') ?></strong>
        </p>
        <p style="margin:0;font-weight:bold;color:#0d6efd;font-size:8px;">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</p>
    </div>
</div>

<!-- More Details Modal -->
<div class="modal fade" id="moreDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white py-3" style="background:#0d6efd;">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-layout-text-window me-2"></i>More Details
                    </h5>
                    <small class="opacity-75"><?= htmlspecialchars($header['list_no']) ?> — <?= htmlspecialchars($header['name']) ?></small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <?php if (empty($details_by_nip)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-box-seam" style="font-size:3rem;opacity:.25;"></i>
                    <p class="mt-3">No NIP details found for this material list.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0 small" id="detailsTable">
                        <thead>
                            <tr class="text-center">
                                <th style="width:5%">S/NO</th>
                                <th class="text-start" style="width:18%">NIP Name</th>
                                <th style="width:12%">Item Code</th>
                                <th style="width:9%">Qty (NIP)</th>
                                <th style="width:8%">Unit (NIP)</th>
                                <th class="text-start" style="width:20%">Component</th>
                                <th style="width:8%">Unit</th>
                                <th style="width:10%">Qty (Component)</th>
                                <th style="width:10%">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $global_row = 0;
                        foreach ($details_by_nip as $nip):
                            $comp_count = count($nip['components']);
                            $first = true;
                            foreach ($nip['components'] as $comp):
                                $global_row++;
                        ?>
                        <tr class="<?= $first ? 'nip-group-first' : '' ?>">
                            <td class="text-center text-muted fw-bold"><?= $global_row ?></td>
                            <?php if ($first): ?>
                            <td class="nip-cell ps-2" rowspan="<?= $comp_count ?>">
                                <?= htmlspecialchars($nip['nip_name']) ?>
                            </td>
                            <td class="text-center nip-cell" rowspan="<?= $comp_count ?>">
                                <?php if (!empty($nip['nip_item_code'])): ?>
                                <span class="badge bg-light text-dark border small"><?= htmlspecialchars($nip['nip_item_code']) ?></span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-bold nip-cell" rowspan="<?= $comp_count ?>">
                                <?= number_format((float)$nip['nip_qty'], 2) ?>
                            </td>
                            <td class="text-center text-muted nip-cell" rowspan="<?= $comp_count ?>">
                                <?= htmlspecialchars($nip['nip_unit'] ?? '') ?: '&mdash;' ?>
                            </td>
                            <?php endif; ?>
                            <td class="ps-2"><?= htmlspecialchars($comp['component_name']) ?></td>
                            <td class="text-center text-muted"><?= htmlspecialchars($comp['component_unit'] ?? '') ?: '&mdash;' ?></td>
                            <td class="text-center fw-bold"><?= number_format((float)$comp['qty_per_unit'], 4) ?></td>
                            <td class="text-center fw-bold text-primary"><?= number_format((float)$comp['total_qty'], 4) ?></td>
                        </tr>
                        <?php $first = false; endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer bg-white border-top justify-content-between">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary shadow-sm fw-bold" onclick="mlPrintDetails()">
                    <i class="bi bi-printer me-1"></i> Print Details
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
$(function(){
    if ($.fn.DataTable && $('#viewMatTable tbody tr').length && !$('#viewMatTable tbody tr td[colspan]').length) {
        window.viewMatDT = $('#viewMatTable').DataTable({
            paging: true,
            pageLength: 10,
            searching: false,
            ordering: false,
            info: false,
            dom: 'rtp'
        });
        $('#viewMatLength').on('change', function(){
            window.viewMatDT.page.len(parseInt(this.value)).draw();
        });
    } else {
        $('#viewMatLength').closest('.d-flex').hide();
    }
});

const NIP_URL         = '<?= rtrim(getUrl(''), '/') ?>';
const ML_COMPANY_NAME = '<?= addslashes($c_name) ?>';
const ML_COMPANY_LOGO = '<?= !empty($c_logo) ? addslashes(getUrl($c_logo)) : '' ?>';
const ML_EXPORT_USER  = '<?= addslashes($export_user) ?>';
const ML_EXPORT_ROLE  = '<?= addslashes($export_role) ?>';
const ML_LIST_NO      = '<?= addslashes($header['list_no']) ?>';
const ML_LIST_NAME    = '<?= addslashes(htmlspecialchars($header['name'])) ?>';

function mlMoreDetails() {
    new bootstrap.Modal(document.getElementById('moreDetailsModal')).show();
}

function mlPrintDetails() {
    var tableHtml = document.getElementById('detailsTable')
        ? document.getElementById('detailsTable').outerHTML
        : '<p>No details available.</p>';

    var d = new Date();
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var now = String(d.getDate()).padStart(2,'0') + ' ' + months[d.getMonth()] + ', ' + d.getFullYear()
        + ' at ' + String(d.getHours() % 12 || 12).padStart(2,'0') + ':'
        + String(d.getMinutes()).padStart(2,'0') + ' ' + (d.getHours() >= 12 ? 'PM' : 'AM');
    var yr = d.getFullYear();

    var logoHtml = ML_COMPANY_LOGO ? '<img src="' + ML_COMPANY_LOGO + '" style="height:55px;margin-bottom:8px;"><br>' : '';

    var html = '<!DOCTYPE html><html><head><title>Details — ' + ML_LIST_NO + '</title>'
        + '<style>'
        + '@page{margin:0.5cm 1cm 1.5cm 1cm;}'
        + 'body{font-family:Arial,sans-serif;font-size:11px;color:#333;margin:0;padding-bottom:1.2cm;}'
        + '.header{text-align:center;border-bottom:2px solid #0d6efd;padding-bottom:10px;margin-bottom:12px;}'
        + '.co-name{font-size:18px;font-weight:bold;color:#0d6efd;margin:4px 0;}'
        + '.meta{display:flex;justify-content:space-between;margin-bottom:10px;font-size:10px;color:#555;border-bottom:1px solid #eee;padding-bottom:6px;}'
        + 'table{width:100%;border-collapse:collapse;}'
        + 'thead{display:table-header-group;}'
        + 'thead tr{background:#0d6efd!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + 'th,td{border:1px solid #dee2e6;padding:4px 7px;}'
        + 'th{font-size:10px;text-align:center;white-space:nowrap;}'
        + 'td{font-size:11px;}'
        + '.nip-cell{background:#e7f0ff!important;font-weight:bold;vertical-align:middle;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.footer{position:fixed!important;bottom:0!important;left:0;right:0;height:1cm;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center;background:#fff!important;border-top:1px solid #ddd!important;font-size:9px;z-index:9999;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '</style></head><body>'
        + '<div class="header">' + logoHtml
        + '<div class="co-name">' + ML_COMPANY_NAME.toUpperCase() + '</div>'
        + '<div style="font-weight:bold;font-size:13px;text-transform:uppercase;">Materials Details</div>'
        + '</div>'
        + '<div class="meta">'
        + '<span><strong>List No:</strong> ' + ML_LIST_NO + ' &nbsp;|&nbsp; <strong>Name:</strong> ' + ML_LIST_NAME + '</span>'
        + '<span><strong>Date:</strong> ' + now + '</span>'
        + '</div>'
        + tableHtml
        + '<div class="footer">'
        + '<p style="margin:0 0 2px;color:#888;">This document was Printed by <strong style="color:#212529;">' + ML_EXPORT_USER + ' - ' + ML_EXPORT_ROLE + '</strong>'
        + ' on <strong style="color:#212529;">' + now + '</strong></p>'
        + '<p style="margin:0;font-weight:bold;color:#0d6efd;">Powered By BJP Technologies &copy; ' + yr + ', All Rights Reserved</p>'
        + '</div></body></html>';

    var win = window.open('', '_blank', 'width=1000,height=700');
    win.document.write(html);
    win.document.close();
    win.onload = function() { win.focus(); win.print(); };
}

function mlExportPdf() {
    var tbodyHtml = '';
    if (window.viewMatDT) {
        window.viewMatDT.rows({page:'all'}).nodes().each(function(n){ tbodyHtml += n.outerHTML; });
    } else {
        var tb = document.querySelector('#viewMatTable tbody');
        tbodyHtml = tb ? tb.innerHTML : '';
    }

    var d = new Date();
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var now = String(d.getDate()).padStart(2,'0') + ' ' + months[d.getMonth()] + ', ' + d.getFullYear()
        + ' at ' + String(d.getHours() % 12 || 12).padStart(2,'0') + ':'
        + String(d.getMinutes()).padStart(2,'0') + ' ' + (d.getHours() >= 12 ? 'PM' : 'AM');
    var yr = d.getFullYear();
    var logoHtml = ML_COMPANY_LOGO
        ? '<img src="' + ML_COMPANY_LOGO + '" style="height:50px;margin-bottom:6px;display:block;margin-left:auto;margin-right:auto;" crossorigin="anonymous">'
        : '';

    var container = document.createElement('div');
    container.style.cssText = 'position:absolute;left:-9999px;top:0;width:190mm;font-family:Arial,sans-serif;font-size:11px;color:#333;background:#fff;padding:8px;';
    container.innerHTML =
        '<div style="text-align:center;border-bottom:2px solid #0d6efd;padding-bottom:10px;margin-bottom:12px;">'
        + logoHtml
        + '<div style="font-size:18px;font-weight:bold;color:#0d6efd;margin:4px 0;">' + ML_COMPANY_NAME.toUpperCase() + '</div>'
        + '<div style="font-weight:bold;font-size:14px;text-transform:uppercase;margin:4px 0;">Materials List</div>'
        + '</div>'
        + '<div style="display:flex;justify-content:space-between;margin-bottom:10px;font-size:10px;color:#555;border-bottom:1px solid #eee;padding-bottom:6px;">'
        + '<span><strong>List No:</strong> ' + ML_LIST_NO + '&nbsp;|&nbsp;<strong>Name:</strong> ' + ML_LIST_NAME + '</span>'
        + '<span><strong>Date:</strong> ' + now + '</span>'
        + '</div>'
        + '<table style="width:100%;border-collapse:collapse;">'
        + '<thead><tr style="background:#0d6efd;color:#fff;">'
        + '<th style="padding:5px 7px;border:1px solid #dee2e6;font-size:10px;width:6%;text-align:center;">S/NO</th>'
        + '<th style="padding:5px 7px;border:1px solid #dee2e6;font-size:10px;width:42%;text-align:left;">Description</th>'
        + '<th style="padding:5px 7px;border:1px solid #dee2e6;font-size:10px;width:12%;text-align:center;">Unit</th>'
        + '<th style="padding:5px 7px;border:1px solid #dee2e6;font-size:10px;width:22%;text-align:center;">Item No (SKU)</th>'
        + '<th style="padding:5px 7px;border:1px solid #dee2e6;font-size:10px;width:12%;text-align:center;">Quantity</th>'
        + '</tr></thead>'
        + '<tbody>' + tbodyHtml + '</tbody>'
        + '</table>'
        + '<div style="margin-top:20px;border-top:1px solid #ddd;padding-top:8px;text-align:center;font-size:9px;color:#888;">'
        + '<div style="margin-bottom:2px;">Exported by <strong style="color:#212529;">' + ML_EXPORT_USER + ' — ' + ML_EXPORT_ROLE + '</strong>'
        + ' on <strong style="color:#212529;">' + now + '</strong></div>'
        + '<div style="font-weight:bold;color:#0d6efd;">Powered By BJP Technologies &copy; ' + yr + ', All Rights Reserved</div>'
        + '</div>';

    document.body.appendChild(container);

    html2pdf().set({
        margin:      [0.8, 1, 1.2, 1],
        filename:    'Materials-List-' + ML_LIST_NO + '.pdf',
        image:       { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, logging: false },
        jsPDF:       { unit: 'cm', format: 'a4', orientation: 'portrait' }
    }).from(container).save().then(function() {
        document.body.removeChild(container);
    });
}
</script>
<?php includeFooter(); ?>
