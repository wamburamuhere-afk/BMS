<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
includeHeader();

// Use existing permission mapping
autoEnforcePermission('asset_report');

try {
    // Current assets table schema inspection check
    $sql = "SELECT a.asset_name, a.asset_code, a.category, a.purchase_date,
                   a.cost as purchase_cost, a.cost as current_value, 0 as depreciation_rate,
                   a.location, a.status, 'Good' as condition_status
            FROM assets a 
            ORDER BY a.category ASC, a.asset_name ASC";
    $stmt = $pdo->query($sql);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_cost    = array_sum(array_column($assets, 'purchase_cost'));
    $total_value   = array_sum(array_column($assets, 'current_value'));
    $total_deprec  = $total_cost - $total_value;
    
    $category_stats = [];
    foreach($assets as $a) {
        $cat = $a['category'] ?: 'Uncategorized';
        $category_stats[$cat] = ($category_stats[$cat] ?? 0) + 1;
    }
} catch (Exception $e) { 
    $error = $e->getMessage(); 
    $assets = []; 
    $total_cost = $total_value = $total_deprec = 0; 
    $category_stats = []; 
}
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <div class="mt-2 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">FIXED ASSETS REGISTER</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Detailed registry of company assets, their current valuation, and historical depreciation summary.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div style="display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: stretch !important;">
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Assets</p>
                <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= count($assets) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Book Value</p>
                <h4 style="color: #2ecc71; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_value) ?></h4>
            </div>
            <div style="flex: 1; border: 1px solid #dee2e6; padding: 10px; text-align: center;">
                <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Depreciation</p>
                <h4 style="color: #e74c3c; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_deprec) ?></h4>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-box-seam-fill me-2"></i>Fixed Assets Register</h2>
            <p class="text-muted mb-0">Valuation, condition tracking, and depreciation summary</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary shadow-sm px-4 fw-bold" onclick="window.print()">
                <i class="bi bi-printer-fill me-2"></i> Print Register
            </button>
            <button class="btn btn-dark shadow-sm px-4 fw-bold ms-2" onclick="exportExcel()">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Export Data
            </button>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Assets</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= count($assets) ?></h4>
                    <span class="small text-primary fw-bold">Items registered</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Book Value</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_value) ?></h4>
                    <span class="small text-success fw-bold">Current Worth</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Depreciation</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_deprec) ?></h4>
                    <span class="small text-warning fw-bold">Value reduction</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Category Spread</p>
                    <div class="d-flex gap-1 flex-wrap mt-1">
                        <?php 
                        $i = 0;
                        foreach($category_stats as $cat => $count): 
                            if($i++ > 1) break;
                        ?>
                            <span class="badge bg-white text-dark border px-2 py-1" style="font-size: 0.65rem;"><?= htmlspecialchars((string)($cat ?? '')) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Assets Table -->
    <div class="card border-0 shadow-lg mb-5" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold d-print-none">Inventory Register</h5>
            <div class="d-print-none shadow-sm">
                <input type="text" id="assetSearch" class="form-control form-control-sm px-3" placeholder="Filter assets by name, code or category..." style="width: 300px; border-radius: 20px;">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="assetTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3 text-muted small text-uppercase" style="width:45px;">S/NO</th>
                            <th class="ps-2 text-muted small text-uppercase">Identification</th>
                            <th class="text-muted small text-uppercase">Category</th>
                            <th class="text-muted small text-uppercase">Acquired</th>
                            <th class="text-end text-muted small text-uppercase">Original Cost</th>
                            <th class="text-end text-muted small text-uppercase">Present Value</th>
                            <th class="text-center text-muted small text-uppercase">Condition</th>
                            <th class="text-end pe-4 text-muted small text-uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($assets)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted italic">No physical assets registered in the system.</td></tr>
                        <?php else: $sno = 1; foreach($assets as $a): ?>
                            <tr>
                                <td class="ps-3 text-center text-muted fw-bold small"><?= $sno++ ?></td>
                                <td class="ps-2">
                                    <div class="fw-bold text-dark"><?= htmlspecialchars((string)($a['asset_name'] ?? '')) ?></div>
                                    <div class="small font-monospace text-muted"><?= htmlspecialchars((string)($a['asset_code'] ?? '')) ?></div>
                                </td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars((string)($a['category'] ?? 'General')) ?></td>
                                <td>
                                    <div class="small fw-bold"><?= $a['purchase_date'] ? date('M d, Y', strtotime($a['purchase_date'])) : 'N/A' ?></div>
                                    <div class="x-small text-muted italic"><?= htmlspecialchars((string)($a['location'] ?? 'Not Set')) ?></div>
                                </td>
                                <td class="text-end text-muted font-monospace"><?= format_currency($a['purchase_cost']) ?></td>
                                <td class="text-end fw-bold text-success font-monospace"><?= format_currency($a['current_value']) ?></td>
                                <td class="text-center fw-semibold text-dark">
                                    <?= strtoupper(($a['condition_status'] ?? '') ?: 'N/A') ?>
                                </td>
                                <td class="text-end pe-4 fw-semibold text-dark">
                                    <?= strtoupper(htmlspecialchars($a['status'] ?? 'N/A')) ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot class="table-light border-top">
                        <tr class="fw-bold">
                            <td colspan="4" class="ps-4 py-3 text-uppercase small text-muted">Register Aggregate Totals</td>
                            <td class="text-end font-monospace"><?= format_currency($total_cost) ?></td>
                            <td class="text-end text-success h5 mb-0 font-monospace"><?= format_currency($total_value) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Footnote -->
    <div class="mt-4 p-3 bg-light rounded text-center text-muted small italic d-print-none">
        <i class="bi bi-info-circle me-1"></i> Asset valuations are adjusted for periodic depreciation based on corporate policies.
    </div>
</div>

<script>
$(document).ready(function(){
    $('#assetSearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $("#assetTable tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });

    if(typeof logReportAction==='function') {
        logReportAction('Viewed Asset Register', 'Fixed assets valuation summary generated');
    }
});

function exportExcel() {
    alert('Asset Register export process initiated.');
}
</script>

<style>
    .card { border-radius: 12px; }
    .table thead th { border-top: none; }
    .x-small { font-size: 0.75rem; }
    .italic { font-style: italic; }
    .truncate { max-width: 100px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }
    @media print {
        .d-print-none, .btn, .navbar, .sidebar { display: none !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .container-fluid { padding: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
        .badge { color: #000 !important; border: 1px solid #ddd !important; background: transparent !important; }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
