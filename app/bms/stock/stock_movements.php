<?php
// File: app/bms/stock/stock_movements.php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

// Check permissions
autoEnforcePermission('products'); // Or a more specific one if available

require_once HEADER_FILE;

// Get filter parameters
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
$movement_type = isset($_GET['movement_type']) ? $_GET['movement_type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 50;
$offset = ($page - 1) * $per_page;

// Get product details if filtered
$product_info = null;
if ($product_id > 0) {
    $p_stmt = $pdo->prepare("SELECT product_name, sku FROM products WHERE product_id = ?");
    $p_stmt->execute([$product_id]);
    $product_info = $p_stmt->fetch(PDO::FETCH_ASSOC);
}

// Build query
$query = "
    SELECT 
        sm.*,
        p.product_name,
        p.sku,
        w.warehouse_name,
        u.username as created_by_name
    FROM stock_movements sm
    LEFT JOIN products p ON sm.product_id = p.product_id
    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON sm.created_by = u.user_id
    WHERE 1=1
";

$params = [];
if ($product_id > 0) {
    $query .= " AND sm.product_id = ?";
    $params[] = $product_id;
}
if ($warehouse_id > 0) {
    $query .= " AND sm.warehouse_id = ?";
    $params[] = $warehouse_id;
}
if (!empty($movement_type)) {
    $query .= " AND sm.movement_type = ?";
    $params[] = $movement_type;
}
if (!empty($date_from)) {
    $query .= " AND DATE(sm.created_at) >= ?";
    $params[] = $date_from;
}
if (!empty($date_to)) {
    $query .= " AND DATE(sm.created_at) <= ?";
    $params[] = $date_to;
}

// Get total count for pagination
$count_query = str_replace("sm.*, p.product_name, p.sku, w.warehouse_name, u.username as created_by_name", "COUNT(*)", $query);
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Add ordering and limit
$query .= " ORDER BY sm.created_at DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for badges
function getMovementBadge($type) {
    $badges = [
        'purchase_in' => ['color' => 'success', 'label' => 'Purchase In'],
        'sale_out' => ['color' => 'primary', 'label' => 'Sale Out'],
        'adjustment_in' => ['color' => 'info', 'label' => 'Adjustment In'],
        'adjustment_out' => ['color' => 'warning', 'label' => 'Adjustment Out'],
        'stock_transfer' => ['color' => 'secondary', 'label' => 'Transfer'],
        'return_in' => ['color' => 'info', 'label' => 'Return In'],
        'return_out' => ['color' => 'danger', 'label' => 'Return Out']
    ];
    
    $config = $badges[$type] ?? ['color' => 'dark', 'label' => ucfirst(str_replace('_', ' ', $type))];
    return '<span class="badge bg-' . $config['color'] . '">' . $config['label'] . '</span>';
}
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
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
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
                    <small class="text-muted fw-normal"> - <?= safe_output($product_info['product_name']) ?> (<?= safe_output($product_info['sku']) ?>)</small>
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
                    <label class="form-label small fw-bold">Warehouse</label>
                    <select class="form-select form-select-sm" name="warehouse_id">
                        <option value="0">All Warehouses</option>
                        <?php
                        $wh_stmt = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status='active'");
                        while ($wh = $wh_stmt->fetch()) {
                            $sel = ($warehouse_id == $wh['warehouse_id']) ? 'selected' : '';
                            echo "<option value='{$wh['warehouse_id']}' $sel>{$wh['warehouse_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Type</label>
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
                    <label class="form-label small fw-bold">From</label>
                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?= $date_from ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small fw-bold">To</label>
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
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-3">Date</th>
                            <th>Type</th>
                            <?php if (!$product_id): ?>
                                <th>Product</th>
                            <?php endif; ?>
                            <th>Warehouse</th>
                            <th>Reference</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Stock Before</th>
                            <th class="text-end">Stock After</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movements)): ?>
                            <tr>
                                <td colspan="<?= $product_id ? 8 : 9 ?>" class="text-center py-5 text-muted">
                                    <i class="bi bi-info-circle fs-2 d-block mb-2"></i>
                                    No stock movements found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($movements as $m): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-bold"><?= format_date($m['created_at']) ?></div>
                                        <small class="text-muted"><?= date('h:i A', strtotime($m['created_at'])) ?></small>
                                    </td>
                                    <td><?= getMovementBadge($m['movement_type']) ?></td>
                                    <?php if (!$product_id): ?>
                                        <td>
                                            <div class="fw-bold"><?= safe_output($m['product_name']) ?></div>
                                            <code class="small"><?= safe_output($m['sku']) ?></code>
                                        </td>
                                    <?php endif; ?>
                                    <td><?= safe_output($m['warehouse_name']) ?></td>
                                    <td>
                                        <div class="small fw-bold text-dark"><?= safe_output($m['reference_number']) ?></div>
                                        <?php if (!empty($m['reason'])): ?>
                                            <small class="text-muted d-block"><?= safe_output($m['reason']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold <?= $m['quantity'] > 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= $m['quantity'] > 0 ? '+' : '' ?><?= format_number($m['quantity'], 3) ?>
                                    </td>
                                    <td class="text-end text-muted"><?= format_number($m['stock_before'], 3) ?></td>
                                    <td class="text-end fw-bold"><?= format_number($m['stock_after'], 3) ?></td>
                                    <td><small class="text-muted"><?= safe_output($m['created_by_name']) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-top-0 py-3">
                    <nav aria-label="Page navigation" class="no-print">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= ($page == $i) ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
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

<?php 
includeFooter();
ob_end_flush();
?>
