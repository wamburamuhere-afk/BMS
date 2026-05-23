<?php
// File: product_view.php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once HEADER_FILE;
global $company_logo, $company_name;

// Check if product ID is provided
if (!isset($_GET['id'])) {
    header("Location: products.php?error=Invalid Product ID");
    exit();
}

$product_id = intval($_GET['id']);

// Check user role for product viewing permissions
requireViewPermission('products');

$can_edit_products = canEdit('products');
$can_delete_products = canDelete('products');
$can_adjust_stock = hasPermission('adjust_stock') || isAdmin();

// Get product details with comprehensive information
try {
    $query = "
        SELECT 
            p.*,
            c.category_name,
            c.parent_id as category_parent_id,
            b.brand_name,
            s.supplier_name,
            t.rate_name AS tax_name,
            t.rate_percentage as tax_rate_percentage,
            u.username as created_by_name,
            u2.username as updated_by_name,
            
            -- Stock information
            COALESCE(SUM(ps.stock_quantity), 0) as total_stock,
            COALESCE(SUM(ps.reserved_quantity), 0) as total_reserved,
            COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) as available_stock,
            
            -- Sales statistics (last 90 days)
            COALESCE((
                SELECT SUM(psi.quantity) 
                FROM pos_sale_items psi 
                JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                WHERE psi.product_id = p.product_id 
                AND ps.sale_status = 'completed'
                AND ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            ), 0) as sales_last_90_days,
            
            -- Total sales (all time)
            COALESCE((
                SELECT SUM(psi.quantity) 
                FROM pos_sale_items psi 
                JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                WHERE psi.product_id = p.product_id 
                AND ps.sale_status = 'completed'
            ), 0) as total_sales_quantity,
            
            -- Total revenue (all time)
            COALESCE((
                SELECT SUM(psi.line_total) 
                FROM pos_sale_items psi 
                JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                WHERE psi.product_id = p.product_id 
                AND ps.sale_status = 'completed'
            ), 0) as total_revenue,
            
            -- Last sale date
            (
                SELECT MAX(ps.sale_date) 
                FROM pos_sale_items psi 
                JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                WHERE psi.product_id = p.product_id 
                AND ps.sale_status = 'completed'
            ) as last_sale_date,
            
            -- Last purchase date
            (
                SELECT MAX(po.order_date) 
                FROM purchase_order_items poi 
                JOIN purchase_orders po ON poi.purchase_order_id  = po.purchase_order_id  
                WHERE poi.product_id = p.product_id 
                AND po.status = 'received'
            ) as last_purchase_date,
            
            -- Average monthly sales
            COALESCE((
                SELECT AVG(monthly_sales) 
                FROM (
                    SELECT MONTH(ps.sale_date) as month, YEAR(ps.sale_date) as year, SUM(psi.quantity) as monthly_sales
                    FROM pos_sale_items psi 
                    JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
                    WHERE psi.product_id = p.product_id 
                    AND ps.sale_status = 'completed'
                    AND ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                    GROUP BY YEAR(ps.sale_date), MONTH(ps.sale_date)
                ) monthly_data
            ), 0) as avg_monthly_sales,
            
            -- Stock value
            COALESCE(SUM(ps.stock_quantity * p.cost_price), 0) as stock_value,
            
            -- Profit margin calculation
            CASE 
                WHEN p.selling_price > 0 AND p.cost_price > 0 
                THEN ROUND(((p.selling_price - p.cost_price) / p.selling_price) * 100, 2)
                ELSE 0 
            END as profit_margin_percentage,
            
            -- Stock status
            CASE 
                WHEN COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= 0 THEN 'out_of_stock'
                WHEN COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= p.min_stock_level THEN 'low_stock'
                ELSE 'in_stock'
            END as stock_status
            
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        LEFT JOIN brands b ON p.brand_id = b.brand_id
        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
        LEFT JOIN tax_rates t ON p.tax_id = t.rate_id
        LEFT JOIN users u ON p.created_by = u.user_id
        LEFT JOIN users u2 ON p.updated_by = u2.user_id
        LEFT JOIN product_stocks ps ON p.product_id = ps.product_id
        WHERE p.product_id = ?
        GROUP BY p.product_id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        header("Location: products.php?error=Product not found");
        exit();
    }
    
} catch (PDOException $e) {
    header("Location: products.php?error=Database error");
    exit();
} 

// Get stock by warehouse
$warehouse_stock = [];
try {
    $query = "
        SELECT 
            ps.*,
            w.warehouse_name,
            w.warehouse_code,
            loc.location_name,
            loc.location_code
        FROM product_stocks ps
        LEFT JOIN warehouses w ON ps.warehouse_id = w.warehouse_id
        LEFT JOIN locations loc ON ps.location_id = loc.location_id
        WHERE ps.product_id = ?
        ORDER BY w.warehouse_name
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $warehouse_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouse_stock = [];
}

// Get recent sales (last 10)
$recent_sales = [];
try {
    $query = "
        SELECT 
            psi.*,
            ps.receipt_number,
            ps.sale_date,
            ps.grand_total as sale_total,
            ps.payment_method,
            c.customer_name
        FROM pos_sale_items psi
        JOIN pos_sales ps ON psi.sale_id = ps.sale_id
        LEFT JOIN customers c ON ps.customer_id = c.customer_id
        WHERE psi.product_id = ?
        AND ps.sale_status = 'completed'
        ORDER BY ps.sale_date DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $recent_sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_sales = [];
}

// Get recent stock movements
$recent_movements = [];
try {
    $query = "
        SELECT 
            sm.*,
            w.warehouse_name,
            u.username as adjusted_by_name
        FROM stock_movements sm
        LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
        LEFT JOIN users u ON sm.created_by = u.user_id
        WHERE sm.product_id = ?
        ORDER BY sm.created_at DESC
        LIMIT 10
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $recent_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_movements = [];
}

// Get sales trend (last 6 months)
$sales_trend = [];
try {
    $query = "
        SELECT 
            DATE_FORMAT(ps.sale_date, '%Y-%m') as month,
            SUM(psi.quantity) as quantity_sold,
            SUM(psi.line_total) as revenue
        FROM pos_sale_items psi
        JOIN pos_sales ps ON psi.sale_id = ps.sale_id
        WHERE psi.product_id = ?
        AND ps.sale_status = 'completed'
        AND ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(ps.sale_date, '%Y-%m')
        ORDER BY month
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $sales_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sales_trend = [];
}

// Get purchase orders for this product
$purchase_orders = [];
try {
    $query = "
        SELECT 
            po.*,
            s.supplier_name,
            COUNT(poi.item_id) as item_count
        FROM purchase_orders po
        JOIN purchase_order_items poi ON po.purchase_order_id  = poi.purchase_order_id 
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        WHERE poi.product_id = ?
        GROUP BY po.purchase_order_id
        ORDER BY po.order_date DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $purchase_orders = [];
}

// Get stock transfers for this product
$stock_transfers = [];
try {
    $query = "
        SELECT 
            st.*,
            w1.warehouse_name as from_warehouse,
            w2.warehouse_name as to_warehouse,
            u.username as transferred_by
        FROM stock_transfers st
        JOIN stock_transfer_items sti ON st.transfer_id = sti.transfer_id
        LEFT JOIN warehouses w1 ON st.from_warehouse_id = w1.warehouse_id
        LEFT JOIN warehouses w2 ON st.to_warehouse_id = w2.warehouse_id
        LEFT JOIN users u ON st.created_by = u.user_id
        WHERE sti.product_id = ?
        ORDER BY st.transfer_date DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$product_id]);
    $stock_transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stock_transfers = [];
}

// Get warehouses for adjustment form
$warehouses = [];
try {
    $warehouses = $pdo->query("SELECT * FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll();
} catch (PDOException $e) {
    $warehouses = [];
}

// Helper functions removed, now in helpers.php
function get_movement_type_badge($type) {
    $badges = [
        'purchase_in' => 'success',
        'sale_out' => 'danger',
        'adjustment_in' => 'info',
        'adjustment_out' => 'warning',
        'correction' => 'warning',
        'damaged' => 'dark',
        'expired' => 'secondary',
        'found' => 'info',
        'theft' => 'danger'
    ];
    
    $labels = [
        'purchase_in' => 'Purchase',
        'sale_out' => 'Sale',
        'adjustment_in' => 'Stock In',
        'adjustment_out' => 'Stock Out',
        'correction' => 'Correction',
        'damaged' => 'Damaged',
        'expired' => 'Expired',
        'found' => 'Found',
        'theft' => 'Theft'
    ];
    
    $color = $badges[$type] ?? 'secondary';
    $label = $labels[$type] ?? $type;
    
    return '<span class="badge bg-' . $color . '">' . $label . '</span>';
}

function get_stock_badge($stock_status, $available_stock) {
    $color = 'secondary';
    $label = 'Unknown';
    
    switch ($stock_status) {
        case 'out_of_stock':
            $color = 'danger';
            $label = 'Out of Stock';
            break;
        case 'low_stock':
            $color = 'warning';
            $label = 'Low Stock';
            break;
        case 'in_stock':
            $color = 'success';
            $label = 'In Stock (' . $available_stock . ')';
            break;
    }
    return '<span class="badge bg-' . $color . '">' . $label . '</span>';
}

function get_progress_color($percentage) {
    if ($percentage >= 80) return 'success';
    if ($percentage >= 50) return 'warning';
    return 'danger';
}

// Calculate days since last sale
$days_since_last_sale = 'N/A';
if (!empty($product['last_sale_date']) && $product['last_sale_date'] != '0000-00-00 00:00:00') {
    $last_sale = strtotime($product['last_sale_date']);
    $now = time();
    $days_since_last_sale = floor(($now - $last_sale) / (60 * 60 * 24));
}

// Calculate performance metrics
$turnover_ratio = 0;
if ($product['avg_monthly_sales'] > 0 && $product['available_stock'] > 0) {
    $turnover_ratio = $product['avg_monthly_sales'] / $product['available_stock'];
}

$stock_coverage = 0;
if ($product['avg_monthly_sales'] > 0) {
    $stock_coverage = $product['available_stock'] / $product['avg_monthly_sales'];
}

$days_since_first_sale = 1;
if (!empty($product['last_sale_date']) && !empty($product['created_at'])) {
    $first_sale = strtotime($product['created_at']);
    $last_sale = strtotime($product['last_sale_date']);
    $days_since_first_sale = max(1, floor(($last_sale - $first_sale) / (60 * 60 * 24)));
}
$sales_velocity = $product['total_sales_quantity'] / $days_since_first_sale;

// Parse product attributes
$attributes = [];
if (!empty($product['attributes'])) {
    $attributes = json_decode($product['attributes'], true);
}
global $company_logo, $company_name;
?>

<div class="container-fluid mt-4">
    <!-- Print-only Header -->
    <div class="d-none d-print-block text-center mb-4">
       
        <h4 class="fw-bold text-dark text-uppercase">PRODUCT DETAILS REPORT</h4>
        <h5 class="text-muted"><?= safe_output($product['product_name']) ?> (<?= safe_output($product['sku']) ?>)</h5>
        <div class="mt-2" style="border-top: 2px solid #0d6efd; width: 150px; margin: 0 auto;"></div>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4 d-print-none px-2 px-md-4">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $product['is_service'] == 1 ? getUrl('services') : getUrl('products') ?>"><?= $product['is_service'] == 1 ? 'Services' : 'Products' ?></a></li>
            <li class="breadcrumb-item active text-truncate" style="max-width: 150px;"><?= safe_output($product['product_name']) ?></li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-3 mb-md-4 d-print-none px-2 px-md-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-start flex-nowrap gap-2">
                <div>
                    <h2 class="mb-0 fs-4 fs-md-2 fw-bold"><i class="bi bi-box"></i> Product View</h2>
                    <p class="text-muted mb-0 small mt-1 d-none d-md-block">View comprehensive information about this product</p>
                    <p class="text-muted mb-0 small mt-1 d-md-none">Product: <?= safe_output($product['sku']) ?></p>
                </div>
                
                <!-- Desktop Actions -->
                <div class="d-none d-md-flex gap-2 ms-auto pt-1 flex-shrink-0">
                    <a href="<?= $product['is_service'] == 1 ? getUrl('services') : getUrl('products') ?>" class="btn btn-outline-secondary btn-sm shadow-sm">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                    <button type="button" class="btn btn-outline-primary btn-sm shadow-sm" onclick="printProductDetails()">
                        <i class="bi bi-printer"></i> Print
                    </button>
                   
                    <?php if ($can_edit_products): ?>
                    <a href="<?= getUrl('product_edit') ?>?id=<?= $product_id ?>&type=<?= $product['is_service'] == 1 ? 'service' : 'inventory' ?>" class="btn btn-primary btn-sm shadow-sm">
                        <i class="bi bi-pencil"></i> Edit <?= $product['is_service'] == 1 ? 'Service' : 'Product' ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($can_adjust_stock): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm shadow-sm" onclick="adjustStock(<?= $product_id ?>)">
                        <i class="bi bi-arrow-left-right"></i> Adjust Stock
                    </button>
                    <?php endif; ?>
                    <?php if ($can_delete_products && $product['status'] == 'inactive'): ?>
                    <button type="button" class="btn btn-outline-danger btn-sm shadow-sm" onclick="deleteProduct(<?= $product_id ?>)">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Mobile Actions (Dropdown) -->
                <div class="d-flex d-md-none ms-auto pt-1 flex-shrink-0">
                    <div class="dropdown">
                        <button class="btn btn-primary btn-sm dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i> Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="z-index: 1060;">
                            <li>
                                <a class="dropdown-item py-2" href="<?= $product['is_service'] == 1 ? getUrl('services') : getUrl('products') ?>">
                                    <i class="bi bi-arrow-left text-secondary me-2"></i> Back to <?= $product['is_service'] == 1 ? 'Services' : 'Products' ?>
                                </a>
                            </li>
                            <li>
                                <button class="dropdown-item py-2" onclick="printProductDetails()">
                                    <i class="bi bi-printer text-info me-2"></i> Print Details
                                </button>
                            </li>
                            <?php if ($can_edit_products): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item py-2 text-primary" href="<?= getUrl('product_edit') ?>?id=<?= $product_id ?>">
                                    <i class="bi bi-pencil me-2"></i> Edit Product
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if ($can_adjust_stock): ?>
                            <li>
                                <button class="dropdown-item py-2 text-info" onclick="adjustStock(<?= $product_id ?>)">
                                    <i class="bi bi-arrow-left-right me-2"></i> Adjust Stock
                                </button>
                            </li>
                            <?php endif; ?>
                            <?php if ($can_delete_products && $product['status'] == 'inactive'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button class="dropdown-item py-2 text-danger" onclick="deleteProduct(<?= $product_id ?>)">
                                    <i class="bi bi-trash me-2"></i> Delete <?= $product['is_service'] == 1 ? 'Service' : 'Product' ?>
                                </button>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <hr class="d-md-none mt-2 mb-0 opacity-25">
        </div>
    </div>

    <!-- Product Overview -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-image"></i> Product Image</h5>
                </div>
                <div class="card-body text-center">
                    <?php if (!empty($product['image_url'])): ?>
                    <img src="<?= getUrl($product['image_url']) ?>" 
                         class="img-fluid rounded mb-3 product-view-image" 
                         style="max-height: 300px; object-fit: contain;"
                         alt="<?= safe_output($product['product_name']) ?>">
                    <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center" 
                         style="height: 300px; background: #f8f9fa; border-radius: 0.375rem;">
                        <div class="text-center">
                            <i class="bi bi-image" style="font-size: 4rem; color: #6c757d;"></i>
                            <p class="text-muted mt-2">No image available</p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mt-3">
                        <?= get_status_badge($product['status']) ?>
                        <?php if ($product['is_service'] == 0): ?>
                            <?= get_stock_badge($product['stock_status'], $product['available_stock']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>        
        <div class="col-md-8">
            <div class="card h-100 border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 fw-bold text-primary"><i class="bi bi-info-circle"></i> Basic Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-6 border-end-md">
                            <h3 class="text-dark fw-bold text-break mb-3"><?= safe_output($product['product_name']) ?></h3>
                            
                            <div class="row g-2">
                                <div class="col-6 col-md-12 mb-2 mb-md-3">
                                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;">SKU:</small> 
                                    <span class="custom-badge mt-1"><?= safe_output($product['sku']) ?></span>
                                </div>
                                
                                <?php if (!empty($product['barcode'])): ?>
                                <div class="col-6 col-md-12 mb-2 mb-md-3">
                                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;">Barcode:</small> 
                                    <span class="custom-badge mt-1"><?= safe_output($product['barcode']) ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-6 col-md-12 mb-2 mb-md-3">
                                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;">Category:</small> 
                                    <span class="custom-badge mt-1"><?= !empty($product['category_name']) ? safe_output($product['category_name']) : 'Uncategorized' ?></span>
                                </div>
                                
                                <?php if (!empty($product['brand_name'])): ?>
                                <div class="col-6 col-md-12 mb-2 mb-md-3">
                                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;">Brand:</small> 
                                    <span class="custom-badge mt-1"><?= safe_output($product['brand_name']) ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($product['supplier_name'])): ?>
                                <div class="col-6 col-md-12 mb-2 mb-md-3">
                                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;">Supplier:</small> 
                                    <span class="custom-badge mt-1"><?= safe_output($product['supplier_name']) ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="col-6 col-md-12 mb-2 mb-md-3">
                                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;">Unit:</small> 
                                    <span class="custom-badge mt-1"><?= safe_output($product['unit']) ?></span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card bg-light h-100 mb-0 shadow-none border-0">
                                <div class="card-body p-2 p-md-3">
                                    <h6 class="card-title fw-bold text-dark border-bottom pb-2 mb-3"><i class="bi bi-tag"></i> Pricing Information</h6>
                                    
                                    <div class="row g-3">
                                        <div class="col-6 col-md-12 mb-1">
                                            <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Cost Price:</small>
                                            <h4 class="text-danger fw-bold mb-0 mt-1 fs-5 fs-md-4"><?= format_currency($product['cost_price']) ?></h4>
                                        </div>
                                        
                                        <div class="col-6 col-md-12 mb-1">
                                            <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Selling Price:</small>
                                            <h4 class="text-success fw-bold mb-0 mt-1 fs-5 fs-md-4"><?= format_currency($product['selling_price']) ?></h4>
                                        </div>
                                        
                                        <?php if ($product['min_selling_price'] > 0): ?>
                                        <div class="col-6 col-md-12 mb-1">
                                            <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Min Price:</small>
                                            <h5 class="text-warning fw-bold mb-0 mt-1"><?= format_currency($product['min_selling_price']) ?></h5>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($product['wholesale_price'] > 0): ?>
                                        <div class="col-6 col-md-12 mb-1">
                                            <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.65rem;">Wholesale:</small>
                                            <h5 class="text-info fw-bold mb-0 mt-1"><?= format_currency($product['wholesale_price']) ?></h5>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="col-12 col-md-12 mb-1 border-top pt-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <small class="text-muted text-uppercase fw-bold" style="font-size: 0.65rem;">Margin:</small>
                                                    <h5 class="text-<?= get_progress_color($product['profit_margin_percentage']) ?> fw-bold mb-0">
                                                        <?= format_number($product['profit_margin_percentage'], 1) ?>%
                                                    </h5>
                                                </div>
                                                <div class="text-end">
                                                    <small class="text-muted text-uppercase fw-bold" style="font-size: 0.65rem;">Profit:</small>
                                                    <h6 class="mb-0 text-dark"><?= format_currency($product['selling_price'] - $product['cost_price']) ?></h6>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($product['description'])): ?>
                    <div class="mt-3">
                        <strong>Description:</strong>
                        <p class="mt-1"><?= nl2br(safe_output($product['description'])) ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <small class="text-muted">Created:</small>
                            <p><?= format_date($product['created_at'], 'd M Y, h:i A') ?> by <?= safe_output($product['created_by_name']) ?></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Last Updated:</small>
                            <p><?= format_date($product['updated_at'], 'd M Y, h:i A') ?> by <?= safe_output($product['updated_by_name']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs for Detailed Information -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <ul class="nav nav-tabs card-header-tabs" id="productTabs" role="tablist">
                        <?php if ($product['is_service'] == 0): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="stock-tab" data-bs-toggle="tab" 
                                    data-bs-target="#stock" type="button" role="tab">
                                <i class="bi bi-boxes"></i> Stock Information
                            </button>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $product['is_service'] == 1 ? 'active' : '' ?>" id="sales-tab" data-bs-toggle="tab" 
                                    data-bs-target="#sales" type="button" role="tab">
                                <i class="bi bi-graph-up"></i> Sales Performance
                            </button>
                        </li>
                        <?php if ($product['is_service'] == 0): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="movements-tab" data-bs-toggle="tab" 
                                    data-bs-target="#movements" type="button" role="tab">
                                <i class="bi bi-arrow-left-right"></i> Stock Movements
                            </button>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="details-tab" data-bs-toggle="tab" 
                                    data-bs-target="#details" type="button" role="tab">
                                <i class="bi bi-list-check"></i> Additional Details
                            </button>
                        </li>
                        <?php if ($can_adjust_stock && $product['is_service'] == 0): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="actions-tab" data-bs-toggle="tab" 
                                    data-bs-target="#actions" type="button" role="tab">
                                <i class="bi bi-gear"></i> Quick Actions
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="productTabsContent">
                        
                        <!-- Stock Information Tab -->
                        <?php if ($product['is_service'] == 0): ?>
                        <div class="tab-pane fade show active" id="stock" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-light border-bottom">
                                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-pie-chart"></i> Stock Summary</h6>
                                        </div>
                                        <div class="card-body" style="background-color: #d1e7dd;">
                                            <div class="row text-center">
                                                <div class="col-6">
                                                    <h2 class="text-primary"><?= format_number($product['total_stock'], 3) ?></h2>
                                                    <small class="text-muted">Total Stock</small>
                                                </div>
                                                <div class="col-6">
                                                    <h2 class="text-success"><?= format_number($product['available_stock'], 3) ?></h2>
                                                    <small class="text-muted">Available Stock</small>
                                                </div>
                                            </div>
                                            
                                            <div class="row text-center mt-3">
                                                <div class="col-6">
                                                    <h4 class="text-danger"><?= format_number($product['total_reserved'], 3) ?></h4>
                                                    <small class="text-muted">Reserved Stock</small>
                                                </div>
                                                <div class="col-6">
                                                    <h4 class="text-warning"><?= format_currency($product['stock_value']) ?></h4>
                                                    <small class="text-muted">Stock Value</small>
                                                </div>
                                            </div>
                                            
                                            <div class="mt-3">
                                                <div class="progress" style="height: 20px;">
                                                    <?php 
                                                    $available_percentage = ($product['total_stock'] > 0) ? 
                                                        ($product['available_stock'] / $product['total_stock']) * 100 : 0;
                                                    $reserved_percentage = ($product['total_stock'] > 0) ? 
                                                        ($product['total_reserved'] / $product['total_stock']) * 100 : 0;
                                                    ?>
                                                    <div class="progress-bar bg-success" style="width: <?= $available_percentage ?>%">
                                                        Available: <?= format_number($available_percentage, 1) ?>%
                                                    </div>
                                                    <div class="progress-bar bg-danger" style="width: <?= $reserved_percentage ?>%">
                                                        Reserved: <?= format_number($reserved_percentage, 1) ?>%
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-center mt-3">
                                                <a href="<?= getUrl('purchase_orders') ?>?product_id=<?= $product_id ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-cart-plus"></i> View All Purchase Orders
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card">
                                        <div class="card-header bg-light border-bottom">
                                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-exclamation-triangle"></i> Stock Alerts</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if ($product['stock_status'] == 'out_of_stock'): ?>
                                            <div class="alert alert-danger">
                                                <i class="bi bi-x-circle"></i>
                                                <strong>Out of Stock!</strong> This product has no available stock.
                                            </div>
                                            <?php elseif ($product['stock_status'] == 'low_stock'): ?>
                                            <div class="alert alert-warning">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                <strong>Low Stock Alert!</strong> 
                                                Stock (<?= $product['available_stock'] ?>) is below reorder level (<?= $product['min_stock_level'] ?>).
                                            </div>
                                            <?php else: ?>
                                            <div class="alert alert-success">
                                                <i class="bi bi-check-circle"></i>
                                                <strong>Stock Level OK</strong> 
                                                Current stock is above reorder level.
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Reorder Level:</small>
                                                    <p><strong><?= format_number($product['min_stock_level'], 3) ?></strong></p>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Min Stock Level:</small>
                                                    <p><strong><?= format_number($product['min_stock_level'], 3) ?></strong></p>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Max Stock Level:</small>
                                                    <p><strong><?= format_number($product['max_stock_level'], 3) ?></strong></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card h-100">
                                        <div class="card-header bg-light border-bottom">
                                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-house-door"></i> Stock by Warehouse</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($warehouse_stock)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>S/NO</th>
                                                            <th>Warehouse</th>
                                                            <th>Location</th>
                                                            <th>Total Stock</th>
                                                            <th>Available</th>
                                                            <th>Reserved</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php 
                                                        $sn = 1;
                                                        foreach ($warehouse_stock as $stock): ?>
                                                        <tr>
                                                            <td><?= $sn++ ?></td>
                                                            <td><?= safe_output($stock['warehouse_name']) ?></td>
                                                            <td><?= safe_output($stock['location_name'] ?? 'N/A') ?></td>
                                                            <td><?= format_number($stock['stock_quantity'], 3) ?></td>
                                                            <td>
                                                                <?= format_number($stock['stock_quantity'] - $stock['reserved_quantity'], 3) ?>
                                                            </td>
                                                            <td><?= format_number($stock['reserved_quantity'], 3) ?></td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center py-4">
                                                <i class="bi bi-box" style="font-size: 3rem; color: #6c757d;"></i>
                                                <p class="text-muted mt-2">No stock information available</p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Sales Performance Tab -->
                        <div class="tab-pane fade <?= $product['is_service'] == 1 ? 'show active' : '' ?>" id="sales" role="tabpanel">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="card mb-4">
                                        <div class="card-header bg-light border-bottom">
                                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-bar-chart"></i> Sales Trend (Last 6 Months)</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($sales_trend)): ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>Month</th>
                                                            <th>Quantity Sold</th>
                                                            <th>Revenue</th>
                                                            <th>Avg Price</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($sales_trend as $trend): ?>
                                                        <tr>
                                                            <td><?= date('M Y', strtotime($trend['month'] . '-01')) ?></td>
                                                            <td><?= format_number($trend['quantity_sold'], 3) ?></td>
                                                            <td><?= format_currency($trend['revenue']) ?></td>
                                                            <td>
                                                                <?= $trend['quantity_sold'] > 0 ? 
                                                                    format_currency($trend['revenue'] / $trend['quantity_sold']) : 
                                                                    'N/A' ?>
                                                            </td>
                                                        </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center py-4">
                                                <i class="bi bi-graph-up" style="font-size: 3rem; color: #6c757d;"></i>
                                                <p class="text-muted mt-2">No sales data available for the last 6 months</p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="card mb-4">
                                        <div class="card-header bg-light border-bottom">
                                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-cash-stack"></i> Sales Statistics</h6>
                                        </div>
                                        <div class="card-body" style="background-color: #d1e7dd;">
                                            <div class="mb-3">
                                                <small class="text-muted">Total Sold (All Time):</small>
                                                <h4><?= format_number($product['total_sales_quantity'], 3) ?> units</h4>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Total Revenue:</small>
                                                <h4><?= format_currency($product['total_revenue']) ?></h4>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Last 90 Days:</small>
                                                <h5><?= format_number($product['sales_last_90_days'], 3) ?> units</h5>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Average Monthly Sales:</small>
                                                <h5><?= format_number($product['avg_monthly_sales'], 3) ?> units</h5>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <small class="text-muted">Last Sale:</small>
                                                <p>
                                                    <?= !empty($product['last_sale_date']) ? 
                                                        format_date($product['last_sale_date']) : 'No sales yet' ?>
                                                    <?php if (is_numeric($days_since_last_sale)): ?>
                                                    <br><small class="text-muted">
                                                        <?= $days_since_last_sale ?> days ago
                                                    </small>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                            <div class="text-center mt-2 px-3 pb-3">
                                                <a href="<?= getUrl('purchase_orders') ?>?product_id=<?= $product_id ?>" class="btn btn-sm btn-outline-primary w-100">
                                                    <i class="bi bi-cart-plus"></i> View All Purchase Orders
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recent Sales -->
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-receipt"></i> Recent Sales (Last 10)</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($recent_sales)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Receipt #</th>
                                                    <th>Customer</th>
                                                    <th>Quantity</th>
                                                    <th>Unit Price</th>
                                                    <th>Total</th>
                                                    <th>Payment</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_sales as $sale): ?>
                                                <tr>
                                                    <td><?= format_date($sale['sale_date']) ?></td>
                                                    <td>
                                                        <a href="<?= getUrl('sales_order_view') ?>?id=<?= $sale['sale_id'] ?>" class="text-decoration-none">
                                                            <?= safe_output($sale['receipt_number']) ?>
                                                        </a>
                                                    </td>
                                                    <td><?= safe_output($sale['customer_name'] ?? 'Walk-in') ?></td>
                                                    <td><?= format_number($sale['quantity'], 3) ?></td>
                                                    <td><?= format_currency($sale['unit_price']) ?></td>
                                                    <td><?= format_currency($sale['line_total']) ?></td>
                                                    <td>
                                                        <span class="badge bg-<?= $sale['payment_method'] == 'cash' ? 'success' : 'primary' ?>">
                                                            <?= ucfirst($sale['payment_method']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-cart" style="font-size: 3rem; color: #6c757d;"></i>
                                        <p class="text-muted mt-2">No recent sales found</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Stock Movements Tab -->
                        <?php if ($product['is_service'] == 0): ?>
                        <div class="tab-pane fade" id="movements" role="tabpanel">
                            <div class="card">
                                <div class="card-header bg-warning text-dark">
                                    <h6 class="mb-0"><i class="bi bi-arrow-left-right"></i> Recent Stock Movements</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($recent_movements)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Type</th>
                                                    <th>Warehouse</th>
                                                    <th>Reference</th>
                                                    <th>Quantity</th>
                                                    <th>Previous</th>
                                                    <th>New</th>
                                                    <th>Adjusted By</th>
                                                    <th>Reason</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_movements as $movement): ?>
                                                <tr>
                                                    <td><?= format_date($movement['created_at'], 'd M Y, h:i A') ?></td>
                                                    <td><?= get_movement_type_badge($movement['movement_type']) ?></td>
                                                    <td><?= safe_output($movement['warehouse_name']) ?></td>
                                                    <td>
                                                        <?php if (!empty($movement['reference_number'])): ?>
                                                        <small class="text-muted"><?= safe_output($movement['reference_number']) ?></small>
                                                        <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="<?= ($movement['quantity'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
                                                        <?= ($movement['quantity'] ?? 0) >= 0 ? '+' : '' ?><?= format_number($movement['quantity'] ?? 0, 3) ?>
                                                    </td>
                                                    <td><?= format_number($movement['stock_before'] ?? 0, 3) ?></td>
                                                    <td><?= format_number($movement['stock_after'] ?? 0, 3) ?></td>
                                                    <td><?= safe_output($movement['adjusted_by_name']) ?></td>
                                                    <td>
                                                        <?php if (!empty($movement['reason'])): ?>
                                                        <small><?= safe_output($movement['reason']) ?></small>
                                                        <?php else: ?>
                                                        <span class="text-muted">No reason provided</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="text-center mt-2">
                                        <a href="<?= getUrl('stock_movements') ?>?product_id=<?= $product_id ?>" class="btn btn-sm btn-outline-primary">
                                            View All Movements
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-arrow-left-right" style="font-size: 3rem; color: #6c757d;"></i>
                                        <p class="text-muted mt-2">No stock movements recorded</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Additional Details Tab -->
                        <div class="tab-pane fade" id="details" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-info text-white">
                                            <h6 class="mb-0"><i class="bi bi-tags"></i> Product Attributes</h6>
                                        </div>
                                        <div class="card-body">
                                            <?php if (!empty($attributes) && is_array($attributes)): ?>
                                            <div class="row">
                                                <?php foreach ($attributes as $key => $value): ?>
                                                <div class="col-md-6 mb-2">
                                                    <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) ?>:</strong>
                                                    <br>
                                                    <span class="badge bg-light text-dark"><?= htmlspecialchars($value) ?></span>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php else: ?>
                                            <div class="text-center py-3">
                                                <i class="bi bi-tag" style="font-size: 2rem; color: #6c757d;"></i>
                                                <p class="text-muted mt-2">No additional attributes defined</p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="card mb-4">
                                        <div class="card-header bg-success text-white">
                                            <h6 class="mb-0"><i class="bi bi-calendar"></i> Date Information</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Created:</small>
                                                    <p><?= format_date($product['created_at'], 'd M Y, h:i A') ?></p>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Last Updated:</small>
                                                    <p><?= format_date($product['updated_at'], 'd M Y, h:i A') ?></p>
                                                </div>
                                            </div>
                                            <?php if (!empty($product['last_purchase_date'])): ?>
                                            <div class="row">
                                                <div class="col-12">
                                                    <small class="text-muted">Last Purchase:</small>
                                                    <p><?= format_date($product['last_purchase_date']) ?></p>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <?php if ($product['is_service'] == 0): ?>
                                    <div class="card mb-4">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0"><i class="bi bi-shield-check"></i> Inventory Settings</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6 mb-3">
                                                    <strong>Track Inventory:</strong><br>
                                                    <span class="badge bg-<?= ($product['track_inventory'] ?? 0) ? 'success' : 'secondary' ?>">
                                                        <?= ($product['track_inventory'] ?? 0) ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Allow Backorders:</strong><br>
                                                    <span class="custom-badge">
                                                        <?= ($product['allow_backorders'] ?? 0) ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Allow Negative Stock:</strong><br>
                                                    <span class="custom-badge">
                                                        <?= ($product['allow_negative_stock'] ?? 0) ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Require Serial Numbers:</strong><br>
                                                    <span class="custom-badge">
                                                        <?= ($product['requires_serial'] ?? 0) ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Require Batch Numbers:</strong><br>
                                                    <span class="custom-badge">
                                                        <?= ($product['requires_batch'] ?? 0) ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                                <div class="col-6 mb-3">
                                                    <strong>Is Expirable:</strong><br>
                                                    <span class="custom-badge">
                                                        <?= ($product['is_expirable'] ?? 0) ? 'Yes' : 'No' ?>
                                                    </span>
                                                </div>
                                            </div>

                                            <?php if (($product['is_expirable'] ?? 0) && !empty($product['shelf_life_days'])): ?>
                                            <div class="alert alert-info mt-3">
                                                <i class="bi bi-clock"></i>
                                                <strong>Shelf Life:</strong> <?= $product['shelf_life_days'] ?> days
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <div class="card">
                                        <div class="card-header bg-light border-bottom">
                                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-people"></i> User Information</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-6">
                                                    <small class="text-muted">Created By:</small>
                                                    <p><?= safe_output($product['created_by_name']) ?></p>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Updated By:</small>
                                                    <p><?= safe_output($product['updated_by_name']) ?></p>
                                                </div>
                                            </div>
                                            <?php if (!empty($product['notes'])): ?>
                                            <div class="mt-3">
                                                <strong>Internal Notes:</strong>
                                                <div class="border rounded p-2 mt-1 bg-light">
                                                    <?= nl2br(safe_output($product['notes'])) ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($can_adjust_stock): ?>
                        <!-- Quick Actions Tab -->
                        <div class="tab-pane fade" id="actions" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-danger text-white">
                                            <h6 class="mb-0"><i class="bi bi-plus-slash-minus"></i> Stock Adjustment</h6>
                                        </div>
                                        <div class="card-body">
                                            <form id="adjustStockForm">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                
                                                <div class="mb-3">
                                                    <label for="adjustment_type" class="form-label">Adjustment Type</label>
                                                    <select class="form-select" id="adjustment_type" name="movement_type" required>
                                                        <option value="adjustment_in">Stock In (Increase)</option>
                                                        <option value="adjustment_out">Stock Out (Decrease)</option>
                                                        <option value="correction">Correction</option>
                                                        <option value="damaged">Damaged</option>
                                                        <option value="expired">Expired</option>
                                                        <option value="found">Found Stock</option>
                                                        <option value="theft">Theft/Loss</option>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label for="warehouse_id" class="form-label">Warehouse</label>
                                                    <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                                                        <option value="">Select Warehouse</option>
                                                        <?php foreach ($warehouses as $warehouse): ?>
                                                        <option value="<?= $warehouse['warehouse_id'] ?>">
                                                            <?= htmlspecialchars($warehouse['warehouse_name']) ?>
                                                        </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label for="quantity" class="form-label">Quantity</label>
                                                    <input type="number" class="form-control" id="quantity" name="quantity" 
                                                           step="0.001" min="0.001" required placeholder="Enter quantity">
                                                </div>

                                                <div class="mb-3">
                                                    <label for="reason" class="form-label">Reason/Notes</label>
                                                    <textarea class="form-control" id="reason" name="reason" rows="3" 
                                                              placeholder="Enter reason for adjustment"></textarea>
                                                </div>

                                                <div class="d-grid gap-2">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-check-circle"></i> Submit Adjustment
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="card mb-4">
                                        <div class="card-header bg-warning text-dark">
                                            <h6 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="d-grid gap-2">
                                                <?php if ($product['status'] == 'active'): ?>
                                                <button type="button" class="btn btn-outline-secondary" onclick="toggleProductStatus(<?= $product_id ?>, 'inactive')">
                                                    <i class="bi bi-pause"></i> Deactivate Product
                                                </button>
                                                <?php else: ?>
                                                <button type="button" class="btn btn-outline-success" onclick="toggleProductStatus(<?= $product_id ?>, 'active')">
                                                    <i class="bi bi-play"></i> Activate Product
                                                </button>
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-outline-info" onclick="printBarcode(<?= $product_id ?>)">
                                                    <i class="bi bi-upc-scan"></i> Print Barcode
                                                </button>

                                                <button type="button" class="btn btn-outline-primary" onclick="duplicateProduct(<?= $product_id ?>)">
                                                    <i class="bi bi-copy"></i> Duplicate Product
                                                </button>

                                                <?php if ($product['track_inventory']): ?>
                                                <button type="button" class="btn btn-outline-dark" onclick="transferStock(<?= $product_id ?>)">
                                                    <i class="bi bi-truck"></i> Transfer Stock
                                                </button>
                                                <?php endif; ?>

                                                <a href="<?= getUrl('stock_movements') ?>?product_id=<?= $product_id ?>" 
                                                   class="btn btn-outline-info">
                                                    <i class="bi bi-file-earmark-text"></i> Generate Movement Report
                                                </a>

                                                <a href="<?= getUrl('product_analysis') ?>?product_id=<?= $product_id ?>" 
                                                   class="btn btn-outline-success">
                                                    <i class="bi bi-graph-up"></i> Generate Sales Report
                                                </a>
                                            </div>
                                        </div>
                                    </div>

                                        <div class="card">
                                            <div class="card-header bg-info text-white">
                                                <h6 class="mb-0"><i class="bi bi-bell"></i> Stock Alerts Setup</h6>
                                            </div>
                                            <div class="card-body">
                                                <form id="alertSettingsForm">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                
                                                <div class="mb-3">
                                                    <label for="reorder_level" class="form-label">Reorder Level</label>
                                                    <input type="number" class="form-control" id="reorder_level" name="reorder_level"
                                                           value="<?= $product['min_stock_level'] ?>" step="0.001" min="0">
                                                </div>

                                                <div class="mb-3">
                                                    <label for="min_stock_level" class="form-label">Minimum Stock Level</label>
                                                    <input type="number" class="form-control" id="min_stock_level" name="min_stock_level"
                                                           value="<?= $product['min_stock_level'] ?>" step="0.001" min="0">
                                                </div>

                                                <div class="mb-3">
                                                    <label for="max_stock_level" class="form-label">Maximum Stock Level</label>
                                                    <input type="number" class="form-control" id="max_stock_level" name="max_stock_level"
                                                           value="<?= $product['max_stock_level'] ?>" step="0.001" min="0">
                                                </div>

                                                <div class="form-check mb-3">
                                                    <input class="form-check-input" type="checkbox" id="email_alerts" name="email_alerts"
                                                           <?= $product['email_alerts'] ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="email_alerts">
                                                        Send email alerts for stock issues
                                                    </label>
                                                </div>

                                                <div class="d-grid">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="bi bi-save"></i> Update Alert Settings
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Related Products Section -->
    <?php if ($product['is_service'] == 0): ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h6 class="mb-0"><i class="bi bi-link"></i> Related Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6><i class="bi bi-cart-plus"></i> Purchase Orders</h6>
                            <?php if (!empty($purchase_orders)): ?>
                            <div class="list-group">
                                <?php foreach ($purchase_orders as $order): ?>
                                <a href="<?= getUrl('purchase_order_details') ?>?id=<?= $order['purchase_order_id'] ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1">PO#<?= $order['order_number'] ?></h6>
                                        <small><?= format_date($order['order_date']) ?></small>
                                    </div>
                                    <p class="mb-1">
                                        <small>Supplier: <?= safe_output($order['supplier_name']) ?></small>
                                    </p>
                                    <small>Status: 
                                        <span class="badge bg-<?= 
                                            $order['status'] == 'received' ? 'success' : 
                                            ($order['status'] == 'pending' ? 'warning' : 
                                            ($order['status'] == 'cancelled' ? 'danger' : 'secondary')) ?>">
                                            <?= ucfirst($order['status']) ?>
                                        </span>
                                    </small>
                                </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-2">
                                <a href="<?= getUrl('purchase_orders') ?>?product_id=<?= $product_id ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-cart-plus"></i> View All Purchase Orders
                                </a>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">No purchase orders found for this product.</p>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <h6><i class="bi bi-arrow-left-right"></i> Stock Transfers</h6>
                            <?php if (!empty($stock_transfers)): ?>
                            <div class="list-group">
                                <?php foreach ($stock_transfers as $transfer): ?>
                                <div class="list-group-item">
                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                        <h6 class="mb-0 fw-bold text-primary">TF#<?= $transfer['transfer_number'] ?></h6>
                                        <small class="text-muted"><i class="bi bi-clock"></i> <?= format_date($transfer['transfer_date'], 'd M Y') ?></small>
                                    </div>
                                    <div class="mb-2 p-2 bg-light rounded border">
                                        <small class="d-flex flex-column gap-1">
                                            <span class="text-secondary"><i class="bi bi-box-arrow-right text-danger"></i> From: <span class="fw-medium text-dark"><?= safe_output($transfer['from_warehouse']) ?></span></span>
                                            <span class="text-secondary"><i class="bi bi-box-arrow-in-right text-success"></i> To: <span class="fw-medium text-dark"><?= safe_output($transfer['to_warehouse']) ?></span></span>
                                        </small>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="badge bg-<?= 
                                            $transfer['status'] == 'completed' ? 'success' : 
                                            ($transfer['status'] == 'in_transit' ? 'warning text-dark' : 
                                            ($transfer['status'] == 'cancelled' ? 'danger' : 'secondary')) ?> bg-opacity-75">
                                            <?= ucfirst($transfer['status']) ?>
                                        </span>
                                        <div class="btn-group btn-group-sm d-print-none">
                                            <button type="button" class="btn btn-outline-primary" onclick="viewTransferItems(<?= $transfer['transfer_id'] ?>, '<?= $transfer['transfer_number'] ?>')" title="View Details">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <a href="<?= getUrl('print_transfer') ?>?id=<?= $transfer['transfer_id'] ?>" target="_blank" class="btn btn-outline-secondary" title="Print Transfer">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3 d-print-none text-end">
                                <a href="<?= getUrl('stock_transfers') ?>?product_id=<?= $product_id ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-gear"></i> View All Transfers <i class="bi bi-caret-right-fill"></i>
                                </a>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">No stock transfers found for this product.</p>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <h6><i class="bi bi-graph-up"></i> Performance Metrics</h6>
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <small class="text-muted">Stock Turnover Ratio:</small>
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                                <div class="progress-bar bg-<?= get_progress_color(min($turnover_ratio * 10, 100)) ?>" 
                                                     style="width: <?= min($turnover_ratio * 100, 100) ?>%">
                                                    <?= format_number($turnover_ratio, 2) ?>
                                                </div>
                                            </div>
                                            <small><?= format_number($turnover_ratio, 2) ?>x</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">Stock Coverage (months):</small>
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                                <div class="progress-bar bg-<?= 
                                                    $stock_coverage >= 3 ? 'danger' : 
                                                    ($stock_coverage >= 2 ? 'warning' : 'success') 
                                                    ?>" 
                                                     style="width: <?= min($stock_coverage * 33.33, 100) ?>%">
                                                    <?= format_number($stock_coverage, 1) ?>
                                                </div>
                                            </div>
                                            <small><?= format_number($stock_coverage, 1) ?>m</small>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">Gross Profit Contribution:</small>
                                        <h4 class="text-success">
                                            <?= format_currency($product['total_revenue'] - ($product['total_sales_quantity'] * $product['cost_price'])) ?>
                                        </h4>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted">Sales Velocity (units/day):</small>
                                        <h5 class="text-primary">
                                            <?= format_number($sales_velocity, 3) ?>
                                            <small class="text-muted">units/day</small>
                                        </h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- View Transfer Modal -->
<div class="modal fade" id="viewTransferModal" tabindex="-1" aria-labelledby="viewTransferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewTransferModalLabel">Transfer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="viewTransferModalBody">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript Functions -->
<script>
// Expose warehouse stock to JS
const productStockByWarehouse = <?= json_encode($warehouse_stock) ?>;

$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Product Details', 'User viewed product #<?= $product_id ?> (<?= addslashes($product['product_name']) ?>)');
    
    // Add logging to print function
    window.printProductDetails = function() {
        logReportAction('Printed Product Details', 'User generated a printed report for product #<?= $product_id ?> (<?= addslashes($product['product_name']) ?>)');
        window.print();
    };

    // Add logging to edit button
    $('.btn-primary').on('click', function() {
        if ($(this).attr('href') && $(this).attr('href').includes('product_edit')) {
            logReportAction('Initiated Product Edit', 'User clicked edit for product #<?= $product_id ?> (<?= addslashes($product['product_name']) ?>)');
        }
    });


});
// Export to Excel (CSV)
function exportToExcel() {
    const productData = {
        'Product Name': '<?= addslashes($product['product_name']) ?>',
        'SKU': '<?= addslashes($product['sku']) ?>',
        'Barcode': '<?= addslashes($product['barcode'] ?? '') ?>',
        'Category': '<?= addslashes($product['category_name'] ?? '') ?>',
        'Brand': '<?= addslashes($product['brand_name'] ?? '') ?>',
        'Unit': '<?= addslashes($product['unit']) ?>',
        'Cost Price': '<?= $product['cost_price'] ?>',
        'Selling Price': '<?= $product['selling_price'] ?>',
        'Total Stock': '<?= $product['total_stock'] ?>',
        'Available Stock': '<?= $product['available_stock'] ?>',
        'Status': '<?= $product['status'] ?>'
    };
    
    let csvContent = "data:text/csv;charset=utf-8,Field,Value\n";
    for (const [key, value] of Object.entries(productData)) {
        csvContent += `"${key}","${value}"\n`;
    }
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "product_<?= $product['sku'] ?>_details.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    Swal.fire({
        icon: 'success',
        title: 'Exported!',
        text: 'Product details exported successfully.',
        timer: 2000,
        showConfirmButton: false
    });
}

function adjustStock(productId) {
    logReportAction('Opened Stock Adjustment', 'User opened stock adjustment form for product #<?= $product_id ?> (<?= addslashes($product['product_name']) ?>)');
    $('#actions-tab').tab('show');
    // Use a small timeout to ensure tab is shown before scrolling
    setTimeout(function() {
        const form = document.getElementById('adjustStockForm');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Highlight the form briefly
            $(form).addClass('shadow-lg border-primary').css('transition', 'all 0.5s');
            setTimeout(() => {
                $(form).removeClass('shadow-lg border-primary');
            }, 2000);
        }
    }, 200);
}

function deleteProduct(productId) {
    Swal.fire({
        title: 'Delete Product?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('api/delete_product') ?>',
                type: 'POST',
                data: { product_id: productId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Deleted Product', 'User deleted product ID: ' + productId);
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Product has been deleted successfully.',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = '<?= getUrl('products') ?>';
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to delete product'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while deleting product'
                    });
                }
            });
        }
    });
}

function toggleProductStatus(productId, newStatus) {
    const action = newStatus === 'active' ? 'activate' : 'deactivate';
    
    Swal.fire({
        title: `${action.charAt(0).toUpperCase() + action.slice(1)} Product?`,
        text: `Are you sure you want to ${action} this product?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: `Yes, ${action} it!`
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('api/update_product_status') ?>',
                type: 'POST',
                data: { product_id: productId, status: newStatus },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Changed Product Status', 'User changed status of product ID ' + productId + ' to ' + newStatus);
                        Swal.fire({
                            icon: 'success',
                            title: 'Updated!',
                            text: response.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message || 'Failed to update status'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'An error occurred while updating status'
                    });
                }
            });
        }
    });
}

function printBarcode(productId) {
    logReportAction('Printed Product Barcode', 'User generated barcodes for product ID: ' + productId);
    window.open(`<?= getUrl('print_barcode') ?>?product_id=${productId}&quantity=10`, '_blank');
}

function duplicateProduct(productId) {
    logReportAction('Duplicated Product', 'User duplicated product ID: ' + productId);
    window.location.href = `<?= getUrl('product_create') ?>?duplicate_id=${productId}`;
}

function transferStock(productId) {
    logReportAction('Initiated Stock Transfer', 'User requested stock transfer for product ID: ' + productId);
    
    // Filter warehouses that have stock
    const availableWh = productStockByWarehouse.filter(w => parseFloat(w.stock_quantity) > 0);
    
    if (availableWh.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'No Stock Available',
            text: 'There is no stock available in any warehouse to transfer.'
        });
        return;
    }
    
    if (availableWh.length === 1) {
        // Direct redirect if only one choice
        window.location.href = `<?= getUrl('stock_transfers') ?>?from_warehouse_id=${availableWh[0].warehouse_id}&product_id=${productId}`;
    } else {
        // Ask user to select source
        let options = {};
        availableWh.forEach(w => {
            options[w.warehouse_id] = `${w.warehouse_name} (Qty: ${parseFloat(w.stock_quantity).toFixed(2)})`;
        });
        
        Swal.fire({
            title: 'Select Source Warehouse',
            text: 'Choose where to transfer stock FROM:',
            input: 'select',
            inputOptions: options,
            showCancelButton: true,
            confirmButtonText: 'Continue to Transfer',
            confirmButtonColor: '#0d6efd',
            inputValidator: (value) => {
                if (!value) return 'You need to choose a warehouse!'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `<?= getUrl('stock_transfers') ?>?from_warehouse_id=${result.value}&product_id=${productId}`;
            }
        });
    }
}

function viewTransferItems(id, number) {
    logReportAction('Viewed Transfer Details', 'User viewed items for transfer #' + number + ' from Product View');
    $('#viewTransferModalLabel').text('Transfer Details: ' + number);
    $('#viewTransferModalBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    
    const modal = new bootstrap.Modal(document.getElementById('viewTransferModal'));
    modal.show();

    $.ajax({
        url: '<?= getUrl('ajax_get_transfer_items.php') ?>',
        type: 'GET',
        data: { id: id },
        success: function(response) {
            $('#viewTransferModalBody').html(response);
        },
        error: function() {
            $('#viewTransferModalBody').html('<div class="alert alert-danger">Error loading items</div>');
        }
    });
}

$(document).ready(function() {
    $('#adjustStockForm').submit(function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        
        $.ajax({
            url: '<?= getUrl('api/adjust_stock') ?>',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    logReportAction('Adjusted Stock', 'User adjusted stock for product via the Details page');
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Stock adjusted successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to adjust stock'
                    });
                }
            },
            error: function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while processing adjustment'
                });
            }
        });
    });

    // Stock Alerts form handler
    $('#alertSettingsForm').submit(function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        const $btn = $(this).find('[type="submit"]');
        const btnOrig = $btn.html();
        $btn.html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...').prop('disabled', true);

        $.ajax({
            url: '<?= getUrl('api/update_product_alerts') ?>',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                $btn.html(btnOrig).prop('disabled', false);
                if (response.success) {
                    logReportAction('Updated Stock Alerts', 'User updated alert thresholds for product #<?= $product_id ?>');
                    Swal.fire({
                        icon: 'success',
                        title: 'Saved!',
                        text: response.message || 'Alert settings updated successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message || 'Failed to save alert settings.'
                    });
                }
            },
            error: function() {
                $btn.html(btnOrig).prop('disabled', false);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while saving alert settings.'
                });
            }
        });
    });

    $('[data-bs-toggle="tooltip"]').tooltip();
    
    $('button[data-bs-toggle="tab"]').on('click', function(e) {
        localStorage.setItem('activeProductTab', $(e.target).attr('data-bs-target'));
    });
    
    const activeTab = localStorage.getItem('activeProductTab');
    if (activeTab) {
        const tabElement = document.querySelector(`button[data-bs-target="${activeTab}"]`);
        if (tabElement) {
            new bootstrap.Tab(tabElement).show();
        }
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid #dee2e6 !important;
    background-color: #fff !important;
}

.card-header {
    background-color: #d1e7dd !important;
    border-bottom: 1px solid #badbcc !important;
    border-radius: 8px 8px 0 0;
}

.card-header h5, .card-header h6 {
    color: #0f5132 !important;
    font-weight: 600;
}

/* Custom badge styling for consistent green theme */
.custom-badge {
    background-color: #d1e7dd !important;
    color: #0f5132 !important;
    padding: 4px 12px;
    border-radius: 6px;
    font-weight: 500;
    display: inline-block;
    border: 1px solid #badbcc;
}

.custom-stat-card {
    background-color: #fff !important;
    border: 1px solid #dee2e6 !important;
}

.custom-stat-card h4, .custom-stat-card p, .custom-stat-card i {
    color: #333 !important;
}

.nav-tabs .nav-link.active {
    background-color: #fff !important;
    border-bottom-color: #fff !important;
    font-weight: 600;
}

/* Mobile Responsive Adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 10px !important;
        padding-right: 10px !important;
        overflow-x: hidden !important;
    }
    
    .row {
        margin-left: -5px !important;
        margin-right: -5px !important;
    }
    
    .col-12, .col-md-4, .col-md-8, .col-6 {
        padding-left: 5px !important;
        padding-right: 5px !important;
    }
    
    .card-header {
        padding: 0.75rem 0.5rem !important;
    }
    
    .card-body {
        padding: 0.75rem 0.5rem !important;
    }
    
    .table-responsive {
        margin-left: -0.5rem;
        margin-right: -0.5rem;
        padding-left: 0.5rem;
        padding-right: 0.5rem;
        border: 0;
    }
    
    .nav-tabs {
        display: flex !important;
        flex-wrap: wrap !important;
        overflow: visible !important;
        border-bottom: 0 !important;
    }
    
    .nav-tabs .nav-item {
        flex: 0 0 50% !important;
        max-width: 50% !important;
        border: 1px solid #dee2e6 !important;
        margin-bottom: -1px !important;
        margin-right: -1px !important;
    }
    
    .nav-tabs .nav-item:last-child {
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }
    
    .nav-tabs .nav-link {
        width: 100% !important;
        padding: 0.75rem 0.5rem !important;
        font-size: 0.8rem !important;
        text-align: center !important;
        border-radius: 0 !important;
        border: 0 !important;
        background-color: #f8f9fa !important;
        color: #4a5568 !important;
        height: 100% !important;
    }
    
    .nav-tabs .nav-link.active {
        background-color: #fff !important;
        color: #0d6efd !important;
        font-weight: 700 !important;
        border-bottom: 3px solid #0d6efd !important;
    }
    
    h2 { font-size: 1.25rem !important; }
    h3 { font-size: 1.15rem !important; }
    
    .product-view-image {
        max-height: 250px !important;
    }
}

@page { margin: 10mm 8mm 16mm 8mm; }
@media print {
    /* ===== HIDE UI CHROME ===== */
    .btn, .breadcrumb, .alert:not(.alert-danger):not(.alert-warning),
    .navbar, footer, nav, .card-header .btn, .d-print-none,
    .dropdown, .sidebar, .d-flex.gap-2, .nav-tabs,
    .card-header-tabs, header {
        display: none !important;
    }

    /* ===== BASE ===== */
    html, body {
        background: white !important;
        font-family: 'Inter', Arial, sans-serif !important;
        font-size: 9pt !important;
        color: #111 !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }

    /* ===== STRIP CONTAINERS ===== */
    .container-fluid, .container, .px-4, .mt-4 {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    /* ===== ALL ROWS: full-width flex ===== */
    .row {
        display: flex !important;
        flex-wrap: wrap !important;
        width: 100% !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }

    /* ===== PRODUCT OVERVIEW: Image (30%) | Basic Info (70%) ===== */
    .row > .col-md-4 {
        flex: 0 0 28% !important;
        max-width: 28% !important;
        padding: 0 6pt 0 0 !important;
        box-sizing: border-box !important;
    }
    .row > .col-md-8 {
        flex: 0 0 72% !important;
        max-width: 72% !important;
        padding: 0 0 0 6pt !important;
        box-sizing: border-box !important;
    }

    /* ===== INNER HALF-COLS (tab content, basic info sub-grid) ===== */
    .col-md-8 .col-md-6,
    .col-md-8 .col-6,
    .tab-pane .col-md-6,
    .tab-pane .col-6 {
        flex: 0 0 50% !important;
        max-width: 50% !important;
        padding: 0 3pt !important;
        box-sizing: border-box !important;
    }

    /* ===== FULL-WIDTH COLS ===== */
    .row > .col-12,
    .row > .col-md-12,
    .tab-pane .col-12 {
        flex: 0 0 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }

    /* ===== TAB SECTIONS (all visible, stacked) ===== */
    .tab-content,
    .tab-content > .tab-pane {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
        width: 100% !important;
    }
    .tab-pane {
        page-break-before: auto;
        margin-top: 8pt;
    }

    /* ===== CARDS ===== */
    .card {
        border: none !important;
        box-shadow: none !important;
        margin-bottom: 6pt !important;
        border-radius: 0 !important;
        overflow: visible !important;
        width: 100% !important;
        page-break-inside: auto !important; /* Allow the big tabs card to break */
        background-color: white !important;
        height: auto !important; /* Force auto height in print */
    }

    /* Keep internal small cards together if possible */
    .tab-pane .card, 
    .col-md-4 .card {
        page-break-inside: avoid !important;
    }

    /* ===== CARD HEADERS: blue underline ===== */
    .card-header {
        background: none !important;
        border: none !important;
        border-bottom: 1.5pt solid #0d6efd !important;
        padding: 2pt 0 !important;
        margin-bottom: 4pt !important;
        border-radius: 0 !important;
        height: auto !important;
    }
    .card-header h5,
    .card-header h6 {
        font-size: 8.5pt !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.4px !important;
        color: #0d6efd !important;
        margin: 0 !important;
    }

    /* ===== CARD BODY ===== */
    .card-body {
        padding: 4pt 0 0 0 !important;
        background-color: white !important;
        height: auto !important;
    }

    /* ===== PRODUCT IMAGE (Smaller in print to save space) ===== */
    .col-md-4 .card-body img {
        max-height: 100pt !important;
        width: auto !important;
        display: block !important;
        margin: 0 auto !important;
    }
    .col-md-4 .card-body [style*="height: 300px"],
    .col-md-4 .card-body .d-flex[style*="height: 300px"] {
        height: 100pt !important;
    }

    /* ===== BASIC INFO FIELDS ===== */
    .mb-3, .mb-2 { margin-bottom: 4pt !important; }
    .mb-4 { margin-bottom: 6pt !important; }

    strong, b {
        font-size: 8pt !important;
        color: #555 !important;
        font-weight: 600 !important;
    }

    /* ===== CUSTOM BADGE (SKU, Category, etc.) ===== */
    .custom-badge {
        background: #e8f5e9 !important;
        color: #1b5e20 !important;
        border: 0.5pt solid #a5d6a7 !important;
        padding: 1pt 4pt !important;
        border-radius: 3pt !important;
        font-size: 8pt !important;
        font-weight: 600 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* ===== PRICING CARD (bg-light inner card) ===== */
    .card.bg-light {
        background: #f7f7f7 !important;
        border: 0.5pt solid #ddd !important;
        border-radius: 4pt !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .card.bg-light .card-body {
        padding: 6pt !important;
    }
    .card.bg-light h4,
    .card.bg-light h5 {
        font-size: 11pt !important;
        font-weight: 700 !important;
        margin-bottom: 2pt !important;
    }
    .card.bg-light small {
        font-size: 6.5pt !important;
        color: #666 !important;
    }

    /* ===== STOCK SUMMARY BOX (bg-light green) ===== */
    [style*="background-color: #d1e7dd"] {
        background-color: #d1e7dd !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        padding: 6pt !important;
        border-radius: 3pt !important;
    }

    /* ===== TABLES ===== */
    .table, table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 8pt !important;
    }
    .table th, .table td {
        padding: 2.5pt 4pt !important;
        border-bottom: 0.5pt solid #ddd !important;
        word-break: break-word !important;
    }
    .table thead th {
        background: #f0f0f0 !important;
        font-weight: 700 !important;
        font-size: 7.5pt !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* ===== PROGRESS BAR ===== */
    .progress {
        height: 8pt !important;
        background: #eee !important;
        border-radius: 2pt !important;
        overflow: hidden !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .progress-bar {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* ===== BADGES ===== */
    .badge {
        border: 0.75pt solid #999 !important;
        background: transparent !important;
        color: #111 !important;
        font-size: 7pt !important;
        font-weight: 700 !important;
        padding: 1pt 3pt !important;
    }

    /* ===== TEXT COLORS ===== */
    .text-muted   { color: #666 !important; }
    .text-primary { color: #0d6efd !important; }
    .text-success { color: #198754 !important; }
    .text-danger  { color: #dc3545 !important; }
    .text-warning { color: #fd7e14 !important; }
    .text-info    { color: #0dcaf0 !important; }
    .text-dark    { color: #111 !important; }
    h2, h3 { font-size: 13pt !important; margin-bottom: 4pt !important; }
    h4 { font-size: 10pt !important; margin: 0 !important; }
    h5 { font-size: 9pt !important; }
    small { font-size: 7pt !important; }
    p { margin-bottom: 3pt !important; font-size: 8.5pt !important; }

    /* ===== PRINT HEADER ===== */
    .d-none.d-print-block {
        display: block !important;
    }
}
</style>

<script>
$(document).ready(function() {
    logReportAction('Viewed Product Details', 'User viewed details for product: <?= addslashes($product['product_name']) ?> (ID: <?= $product_id ?>)');
});
</script>

<?php
includeFooter();
ob_end_flush();
?>