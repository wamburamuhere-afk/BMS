<?php
// File: service_view.php
ob_start();
require_once 'header.php';

// Check user role for product permissions
requireViewPermission('products');

$product_id   = isset($_GET['id']) ? intval($_GET['id']) : 0;
$from_project = isset($_GET['from_project']) ? intval($_GET['from_project']) : 0;

if ($product_id <= 0) {
    header("Location: " . getUrl('services'));
    exit();
}

$back_url   = $from_project ? getUrl('project_view') . '?id=' . $from_project . '#proc-nip-products' : getUrl('services');
$back_label = $from_project ? 'Back to Project' : 'Back to List';

// Fetch product details
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        c.category_name,
        b.brand_name,
        s.supplier_name,
        t.rate_name AS tax_name,
        t.rate_percentage as tax_rate_percentage,
        w.warehouse_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN brands b ON p.brand_id = b.brand_id
    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
    LEFT JOIN tax_rates t ON p.tax_id = t.rate_id
    LEFT JOIN warehouses w ON p.warehouse_id = w.warehouse_id
    WHERE p.product_id = ? AND p.is_service = 1
");
$stmt->execute([$product_id]);
$svc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$svc) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Service product not found.</div></div>";
    require_once 'footer.php';
    exit();
}

// Fetch components (with component cost_price in one query)
$comp_stmt = $pdo->prepare("
    SELECT ac.id, ac.component_product_id, ac.unit, ac.qty_per_unit, ac.total_qty,
           cp.product_name, cp.sku,
           COALESCE(cp.cost_price, 0) AS component_cost
    FROM product_assembly_components ac
    JOIN products cp ON ac.component_product_id = cp.product_id
    WHERE ac.parent_product_id = ?
    ORDER BY cp.product_name ASC
");
$comp_stmt->execute([$product_id]);
$components = $comp_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals from fetched data
$assembly_cost = 0;
foreach ($components as $comp) {
    $assembly_cost += ($comp['component_cost'] * ($comp['qty_per_unit'] ?? 0));
}

$margin = 0;
if ($svc['selling_price'] > 0) {
    $margin = (($svc['selling_price'] - $assembly_cost) / $svc['selling_price']) * 100;
}

// Global company name for branding
$display_company_name = $GLOBALS['DISPLAY_COMPANY_NAME'] ?? 'BUSINESS MANAGEMENT SYSTEM';
$company_logo = getSetting('company_logo', '');
?>

<style>
@media (max-width: 768px) {
    /* Stack Dashboard Stats */
    .dashboard-stat-col {
        width: 100% !important;
        margin-bottom: 8px;
    }
    .dashboard-stat-card {
        padding: 12px !important;
    }
    
    /* Responsive Table to Cards */
    #svcViewCompTable thead { display: none; }
    #svcViewCompTable, #svcViewCompTable tbody, #svcViewCompTable tr, #svcViewCompTable td {
        display: block;
        width: 100%;
    }
    #svcViewCompTable tr {
        margin-bottom: 15px;
        border: 1px solid #eee;
        border-radius: 12px;
        padding: 10px;
        background: #fff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
    #svcViewCompTable td {
        text-align: right !important;
        padding: 8px 10px !important;
        border: none !important;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.8rem;
    }
    #svcViewCompTable td::before {
        content: attr(data-label);
        font-weight: bold;
        color: #666;
        text-transform: uppercase;
        font-size: 0.65rem;
    }
    #svcViewCompTable td:first-child {
        background: #f8f9fa;
        margin: -10px -10px 10px -10px;
        border-radius: 11px 11px 0 0;
        border-bottom: 1px solid #eee !important;
    }

    /* Small Buttons on Mobile */
    .btn-mobile-sm {
        padding: 4px 8px !important;
        font-size: 0.7rem !important;
    }
    
    /* Label-Value Grid for Specs */
    .spec-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #eee;
    }
    .spec-item:last-child { border-bottom: none; }
    .spec-label { color: #666; font-size: 0.75rem; font-weight: 500; }
    .spec-value { font-weight: bold; font-size: 0.8rem; text-align: right; }
    /* Header Responsive Layout */
    .service-header-wrapper {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 15px !important;
    }
    .service-header-title { font-size: 1.25rem !important; }
    .service-header-buttons { width: 100%; display: flex; gap: 8px; }
    .service-header-buttons .btn { flex: 1; }
}
</style>

<div class="container-fluid mt-4 px-4">
    <!-- Header Navigation -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none service-header-wrapper">
        <div>
            <h2 class="fw-bold mb-0 text-primary service-header-title"><i class="bi bi-layout-text-window me-2"></i> Product Dashboard</h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size: 0.75rem;">
                    <li class="breadcrumb-item"><a href="<?= $back_url ?>">
                        <?= $from_project ? 'Project' : 'Services' ?>
                    </a></li>
                    <li class="breadcrumb-item active text-truncate" style="max-width: 150px;" aria-current="page"><?= htmlspecialchars($svc['product_name']) ?></li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2 service-header-buttons">
            <button class="btn btn-outline-primary fw-bold btn-mobile-sm" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <a href="<?= $back_url ?>" class="btn btn-secondary fw-bold btn-mobile-sm">
                <i class="bi bi-arrow-left me-1"></i> <?= $back_label ?>
            </a>
        </div>
    </div>

    <!-- Print Only Branding -->
    <div class="text-center mb-4 report-header d-none d-print-block">
        
        <h3 class="fw-bold mb-1" style="color:#000!important;text-transform:uppercase;">NON-INVENTORY PRODUCT DETAILS</h3>
        <h5 class="text-dark fw-bold mb-1"><?= htmlspecialchars($svc['product_name']) ?></h5>
        <div class="mx-auto bg-primary" style="width:60px;height:3px;border-radius:2px;"></div>
    </div>

    <!-- Dashboard Content -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
        <!-- Clean White Dashboard Header -->
        <div class="bg-white p-4 border-bottom">
            <div class="row align-items-center g-3">
                <div class="col-md-5">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary bg-opacity-10 rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                            <i class="bi bi-gear-wide-connected fs-3 text-primary"></i>
                        </div>
                        <div>
                            <h3 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($svc['product_name']) ?></h3>
                            <span class="badge bg-light text-primary border border-primary border-opacity-25 mt-1">SKU: <?= htmlspecialchars($svc['sku'] ?: 'N/A') ?></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="row g-2 justify-content-md-end">
                        <div class="col-md-4 dashboard-stat-col">
                            <div class="p-2 bg-light rounded text-center border dashboard-stat-card">
                                <div class="d-flex justify-content-between align-items-center px-2">
                                    <small class="text-muted fw-bold uppercase" style="font-size: 0.65rem;">SELLING</small>
                                    <div class="fw-bold text-success" style="white-space: nowrap;">TZS <?= number_format($svc['selling_price'], 2) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 dashboard-stat-col">
                            <div class="p-2 bg-light rounded text-center border dashboard-stat-card">
                                <div class="d-flex justify-content-between align-items-center px-2">
                                    <small class="text-muted fw-bold uppercase" style="font-size: 0.65rem;">COST</small>
                                    <div class="fw-bold text-primary" style="white-space: nowrap;">TZS <?= number_format($assembly_cost, 2) ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 dashboard-stat-col">
                            <div class="p-2 bg-light rounded text-center border dashboard-stat-card">
                                <div class="d-flex justify-content-between align-items-center px-2">
                                    <small class="text-muted fw-bold uppercase" style="font-size: 0.65rem;">MARGIN</small>
                                    <div class="fw-bold text-danger" style="white-space: nowrap;"><?= number_format($margin, 2) ?>%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-body p-4">
            <div class="row g-4">
                <!-- Left Column: Specs -->
                <div class="col-md-6">
                    <div class="p-4 bg-light rounded-4 h-100 border">
                        <h6 class="fw-bold text-dark text-uppercase small mb-3 border-bottom pb-2">
                            <i class="bi bi-info-circle me-2 text-primary"></i> Basic Specifications
                        </h6>
                        <div class="d-flex flex-column gap-1">
                            <div class="spec-item">
                                <span class="spec-label">Tax Rate</span>
                                <span class="spec-value">
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25" style="font-size: 0.65rem;">
                                        <?= htmlspecialchars($svc['tax_name'] ?: 'No Tax') ?> 
                                        <?= $svc['tax_rate_percentage'] ? '('.$svc['tax_rate_percentage'].'%)' : '' ?>
                                    </span>
                                </span>
                            </div>
                            <div class="mt-2">
                                <label class="spec-label d-block mb-1">Description</label>
                                <div class="bg-white p-2 rounded border small text-dark" style="min-height: 50px;">
                                    <?= nl2br(htmlspecialchars($svc['description'] ?: 'No description.')) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Assembly -->
                <div class="col-md-6">
                    <div class="p-4 bg-light rounded-4 h-100 border">
                        <h6 class="fw-bold text-dark text-uppercase small mb-3 border-bottom pb-2">
                            <i class="bi bi-layers me-2 text-primary"></i> Assembly Information
                        </h6>
                        <div class="d-flex flex-column gap-1">
                            <div class="spec-item">
                                <span class="spec-label">Contract Item No</span>
                                <span class="spec-value text-primary"><?= htmlspecialchars($svc['contract_item_no'] ?: '---') ?></span>
                            </div>
                            <div class="spec-item">
                                <span class="spec-label">Base Assembly Qty</span>
                                <span class="spec-value"><?= number_format($svc['assembly_quantity'] ?: 1, 2) ?> Jobs</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bottom: Material List -->
                <div class="col-12">
                    <div class="bg-white rounded-4 border overflow-hidden mt-2">
                        <div class="p-3 bg-primary text-white d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-0 fw-bold"><i class="bi bi-list-check me-2"></i> Material Components List</h6>
                                <small class="opacity-75" id="svcCompCount">Loading...</small>
                            </div>
                            <div class="d-flex gap-2 d-print-none">
                                <?php if ($from_project > 0): ?>
                                <a href="<?= getUrl('project_view') ?>?id=<?= $from_project ?>&open_add=1" class="btn btn-sm btn-light fw-bold btn-mobile-sm">
                                    <i class="bi bi-boxes me-1"></i> Add Materials
                                </a>
                                <?php else: ?>
                                <a href="<?= getUrl('nip_materials') ?>" class="btn btn-sm btn-light fw-bold btn-mobile-sm">
                                    <i class="bi bi-boxes me-1"></i> Add Materials
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="svcViewCompTable">
                                <thead class="bg-light">
                                    <tr class="small text-uppercase">
                                        <th class="ps-4 py-3" style="width:6%;">S/No</th>
                                        <th>Material Name</th>
                                        <th style="width:10%;">Unit</th>
                                        <th class="text-end" style="width:14%;">Qty / Unit</th>
                                        <th class="text-end" style="width:18%;">Unit Cost (TSh)</th>
                                        <th class="text-end" style="width:18%;">Total Cost (TSh)</th>
                                    </tr>
                                </thead>
                                <tbody id="svcCompTbody">
                                    <tr><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>
                                </tbody>
                                <tfoot class="bg-light" id="svcCompTfoot" style="display:none;">
                                    <tr>
                                        <td colspan="5" class="text-end fw-bold py-3">Total Material Cost:</td>
                                        <td class="text-end fw-bold text-primary fs-6 py-3" id="svcTotalCostDisplay"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light border-top d-print-none" id="svcCompPaginationBar" style="display:none;">
                            <div id="svcCompPaginationInfo" class="text-muted small"></div>
                            <nav><ul class="pagination pagination-sm mb-0" id="svcCompPaginationControls"></ul></nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var SVC_COMP_PER_PAGE = 10;
var svcCompCurrentPage = 1;

function loadSvcComponents(page) {
    svcCompCurrentPage = page;
    var $tbody = $('#svcCompTbody');
    $tbody.html('<tr><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>');
    $('#svcCompTfoot').hide();
    $('#svcCompPaginationBar').hide();

    $.getJSON('<?= getUrl('api/get_service_components') ?>', {
        product_id: <?= $product_id ?>,
        page: page,
        per_page: SVC_COMP_PER_PAGE
    }, function(res) {
        if (!res.success) {
            $tbody.html('<tr><td colspan="6" class="text-center py-3 text-danger">Failed to load components.</td></tr>');
            return;
        }
        var p = res.pagination;
        var comps = res.components;
        var startIdx = (p.page - 1) * p.per_page;

        $('#svcCompCount').text(p.total + ' component' + (p.total !== 1 ? 's' : ''));

        if (!comps.length) {
            $tbody.html('<tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-3 opacity-25"></i>No material components defined.</td></tr>');
            return;
        }

        var rows = '';
        $.each(comps, function(i, c) {
            var rowCost = parseFloat(c.component_cost) * parseFloat(c.qty_per_unit);
            var nameSafe = $('<span>').text(c.product_name).html();
            var skuSafe  = $('<span>').text(c.sku || '').html();
            var unitSafe = $('<span>').text(c.unit || '').html();
            rows += '<tr>'
                + '<td class="ps-4 fw-bold text-muted" data-label="S/No">' + (startIdx + i + 1) + '</td>'
                + '<td data-label="Material Name"><div class="fw-bold text-dark">' + nameSafe + '</div>'
                + (c.sku ? '<small class="text-muted">' + skuSafe + '</small>' : '')
                + '</td>'
                + '<td data-label="Unit"><span class="badge bg-light text-dark border">' + unitSafe + '</span></td>'
                + '<td class="text-end fw-bold text-primary" data-label="Qty/Unit">' + parseFloat(c.qty_per_unit).toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>'
                + '<td class="text-end text-muted" data-label="Unit Cost">' + parseFloat(c.component_cost).toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>'
                + '<td class="text-end fw-bold" data-label="Total Cost">' + rowCost.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</td>'
                + '</tr>';
        });
        $tbody.html(rows);

        $('#svcTotalCostDisplay').text(parseFloat(res.grand_total).toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}));
        $('#svcCompTfoot').show();

        var from = startIdx + 1;
        var to   = Math.min(startIdx + comps.length, p.total);
        $('#svcCompPaginationInfo').text('Showing ' + from + ' to ' + to + ' of ' + p.total + ' entries');

        var html = '';
        html += '<li class="page-item ' + (p.page <= 1 ? 'disabled' : '') + '"><a class="page-link" href="#" onclick="loadSvcComponents(' + (p.page - 1) + ');return false;">Previous</a></li>';
        for (var pg = 1; pg <= p.pages; pg++) {
            if (pg === 1 || pg === p.pages || (pg >= p.page - 1 && pg <= p.page + 1)) {
                html += '<li class="page-item ' + (pg === p.page ? 'active' : '') + '"><a class="page-link" href="#" onclick="loadSvcComponents(' + pg + ');return false;">' + pg + '</a></li>';
            } else if (pg === p.page - 2 || pg === p.page + 2) {
                html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }
        html += '<li class="page-item ' + (p.page >= p.pages ? 'disabled' : '') + '"><a class="page-link" href="#" onclick="loadSvcComponents(' + (p.page + 1) + ');return false;">Next</a></li>';
        $('#svcCompPaginationControls').html(html);
        $('#svcCompPaginationBar').show();

    }).fail(function() {
        $tbody.html('<tr><td colspan="6" class="text-center py-3 text-danger">Server error. Please refresh.</td></tr>');
    });
}

$(function() { loadSvcComponents(1); });
</script>

<?php require_once 'footer.php'; ?>
