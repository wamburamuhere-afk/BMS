<?php
// File: app/bms/stock/stock_movements.php
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';

// Check permissions
autoEnforcePermission('products');

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View Stock History', 'Viewed stock movements / history');

// Get filter parameters
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
$movement_type = isset($_GET['movement_type']) ? $_GET['movement_type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

if ($warehouse_id > 0 && !userCan('warehouse', $warehouse_id)) {
    $warehouse_id = 0; // ignore an out-of-scope filter rather than error on a list page
}

// Get product details if filtered
$product_info = null;
if ($product_id > 0) {
    $p_stmt = $pdo->prepare("SELECT product_name, sku FROM products WHERE product_id = ?");
    $p_stmt->execute([$product_id]);
    $product_info = $p_stmt->fetch(PDO::FETCH_ASSOC);
}

// Rows come from api/stock/get_stock_movements.php (server-side DataTable below).
?>

<div class="container-fluid py-4">
    <!-- Print Header (Visible only when printing) -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        ?>
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3 text-center">
                <img src="<?= getUrl($c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= caseFormat($c_name) ?></h1>
        
        <div class="mt-3 text-center">
            <h2 style="color: #000; font-weight: 700; text-transform: uppercase; margin: 5px 0; font-size: 18pt; letter-spacing: 1px;">STOCK MOVEMENT REPORT</h2>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <div class="row mb-4 align-items-center no-print">
        <div class="col">
            <h2 class="fw-bold text-dark mb-0">
                <i class="bi bi-arrow-left-right text-primary me-2"></i> 
                Stock Movements
                <?php if ($product_info): ?>
                    <small class="text-muted fw-normal"> - <?= caseFormat($product_info['product_name']) ?> (<?= caseFormat($product_info['sku']) ?>)</small>
                <?php endif; ?>
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= getUrl('products') ?>">Inventory</a></li>
                    <li class="breadcrumb-item active">Movements</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-primary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i> Print Report
            </button>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card shadow-sm border-0 mb-4 no-print">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <?php if ($product_id > 0): ?>
                    <input type="hidden" name="product_id" value="<?= $product_id ?>">
                <?php endif; ?>
                
                <div class="col-md-3">
                    <label class="form-label small fw-bold"><?= wLabel('Warehouse', 'Shop') ?></label>
                    <select class="form-select form-select-sm select2-static" id="smWarehouseFilter" name="warehouse_id">
                        <option value="0"><?= wLabel('All Warehouses', 'All Shops') ?></option>
                        <?php
                        // Shared helper — also respects the user's direct warehouse
                        // grant (Phase 6, pos_upgrade_plan.md).
                        foreach (warehousesForSelect($pdo) as $wh) {
                            $sel = ($warehouse_id == $wh['warehouse_id']) ? 'selected' : '';
                            echo "<option value='{$wh['warehouse_id']}' $sel>" . htmlspecialchars($wh['warehouse_name']) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold"><?= t('Type') ?></label>
                    <select class="form-select form-select-sm" name="movement_type">
                        <option value="">All Types</option>
                        <option value="purchase_in" <?= $movement_type == 'purchase_in' ? 'selected' : '' ?>>Purchase In</option>
                        <option value="sale_out" <?= $movement_type == 'sale_out' ? 'selected' : '' ?>>Sale Out</option>
                        <option value="adjustment_in" <?= $movement_type == 'adjustment_in' ? 'selected' : '' ?>>Adjustment In</option>
                        <option value="adjustment_out" <?= $movement_type == 'adjustment_out' ? 'selected' : '' ?>>Adjustment Out</option>
                        <option value="stock_transfer" <?= $movement_type == 'stock_transfer' ? 'selected' : '' ?>>Transfer</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold"><?= t('From') ?></label>
                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?= $date_from ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold"><?= t('To') ?></label>
                    <input type="date" class="form-control form-control-sm" name="date_to" value="<?= $date_to ?>">
                </div>
                
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                    <a href="<?= getUrl('stock_movements') ?><?= $product_id > 0 ? '?product_id='.$product_id : '' ?>" class="btn btn-light btn-sm flex-grow-1">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="movementsTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3"><?= t('Date') ?></th>
                            <th><?= t('Type') ?></th>
                            <?php if (!$product_id): ?>
                                <th><?= t('Product') ?></th>
                            <?php endif; ?>
                            <th><?= wLabel('Warehouse', 'Shop') ?></th>
                            <th><?= t('Reference') ?></th>
                            <th class="text-end"><?= t('Quantity') ?></th>
                            <th class="text-end"><?= t('Stock Before') ?></th>
                            <th class="text-end"><?= t('Stock After') ?></th>
                            <th><?= t('By') ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            
        </div>
    </div>
</div>

<style>
@media print {
    .no-print, .navbar, .sidebar, footer { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .container-fluid { padding: 0 !important; }
    table { border: 1px solid #dee2e6 !important; }
}
</style>

<script>
// Server-side DataTable over api/stock/get_stock_movements.php: the server pages,
// sorts and searches with the same filters the form above submits.
// §UI-3 — searchable Select2 on the DB-backed Warehouse filter.
$(function () {
    $('#movementsTable').DataTable({
        processing: true,
        serverSide: true,
        responsive: false,
        autoWidth: false,
        pageLength: 50,
        order: [[0, 'desc']],
        dom: '<"d-flex justify-content-end px-3 pt-3 no-print"f>rt<"d-flex justify-content-between align-items-center px-3 py-3 no-print"ip>',
        ajax: {
            url: '<?= buildUrl('api/stock/get_stock_movements.php') ?>',
            data: d => Object.assign(d, <?= json_encode([
                'product_id' => $product_id, 'warehouse_id' => $warehouse_id,
                'movement_type' => $movement_type, 'date_from' => $date_from, 'date_to' => $date_to,
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)
        },
        columnDefs: [{ targets: <?= $product_id ? '[4, 5, 6]' : '[5, 6, 7]' ?>, className: 'text-end' }, { targets: 0, className: 'ps-3' }],
        language: {
            emptyTable: '<div class="text-muted py-4"><i class="bi bi-info-circle fs-2 d-block mb-2"></i>No stock movements found.</div>'
        }
    });

    if ($('#smWarehouseFilter').length && !$('#smWarehouseFilter').hasClass('select2-hidden-accessible')) {
        $('#smWarehouseFilter').select2({ theme: 'bootstrap-5', width: '100%' });
    }
});
</script>

<?php
includeFooter();
ob_end_flush();
?>
