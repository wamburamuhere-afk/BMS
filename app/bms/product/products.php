<?php
// File: products.php
// scope-audit: skip — multi-project scope enforced below via p.project_id (NULL = global, IN = scoped)
ob_start();
require_once 'header.php';

// Check user role for product permissions
requireViewPermission('products');

logActivity($pdo, $_SESSION['user_id'], 'View products', 'User viewed the products management list');

$can_create_products = canCreate('products');
$can_edit_products = canEdit('products');
$can_delete_products = canDelete('products');
$can_adjust_stock = hasPermission('adjust_stock') || isAdmin(); // Assuming adjust_stock key exists or fallback to Admin

// Use global company name
$display_company_name = $GLOBALS['DISPLAY_COMPANY_NAME'] ?? 'BUSINESS MANAGEMENT SYSTEM';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_id = isset($_GET['category']) ? intval($_GET['category']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';
$supplier_id = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
$brand_id = isset($_GET['brand']) ? intval($_GET['brand']) : 0;
$min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
$max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
$low_stock = isset($_GET['low_stock']) ? $_GET['low_stock'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'product_name';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'ASC';

// Attention mode — dashboard "Inventory & Products" deep-link (?attention=1).
// Shows ONLY products that need attention (low/out/negative stock, or expiring
// within 30 days), all on one page, active only — mirrors get_system_alerts().
$attention = (isset($_GET['attention']) && $_GET['attention'] === '1');
if ($attention) {
    $status_filter = 'active';
    $page = 1;
    $per_page = 1000;
}

// Calculate offset
$offset = ($page - 1) * $per_page;

// Build query with filters
$query = "
    SELECT 
        p.*,
        c.category_name,
        b.brand_name,
        s.supplier_name,
        t.rate_name AS tax_name,
        t.rate_percentage as tax_rate_percentage,
        
        -- Stock information
        COALESCE(SUM(ps.stock_quantity), 0) as total_stock,
        COALESCE(SUM(ps.reserved_quantity), 0) as total_reserved,
        COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) as available_stock,
        
        -- Warehouse information
        GROUP_CONCAT(DISTINCT w.warehouse_name SEPARATOR ', ') as warehouses,
        GROUP_CONCAT(DISTINCT loc.location_name SEPARATOR ', ') as locations,
        
        -- Sales statistics
        COALESCE((
            SELECT SUM(quantity) 
            FROM pos_sale_items psi 
            JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
            WHERE psi.product_id = p.product_id 
            AND ps.sale_status = 'completed'
            AND ps.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ), 0) as sales_last_30_days,
        
        -- Average cost and margin
        COALESCE(p.cost_price, 0) as cost_price,
        p.selling_price,
        CASE 
            WHEN p.cost_price > 0 
            THEN ROUND(((p.selling_price - p.cost_price) / p.cost_price) * 100, 2)
            ELSE 0 
        END as markup_percentage,
        
        -- Stock status
        CASE 
            WHEN COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= 0 THEN 'out_of_stock'
            WHEN COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= p.min_stock_level THEN 'low_stock'
            ELSE 'in_stock'
        END as stock_status,
        
        -- Last restock date
        (
            SELECT MAX(sm.created_at) 
            FROM stock_movements sm 
            WHERE sm.product_id = p.product_id 
            AND sm.movement_type = 'purchase_in'
        ) as last_restock_date,
        
        -- Last sale date
        (
            SELECT MAX(ps.sale_date) 
            FROM pos_sale_items psi 
            JOIN pos_sales ps ON psi.sale_id = ps.sale_id 
            WHERE psi.product_id = p.product_id 
            AND ps.sale_status = 'completed'
        ) as last_sale_date
        
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN brands b ON p.brand_id = b.brand_id
    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
    LEFT JOIN tax_rates t ON p.tax_id = t.rate_id
    LEFT JOIN product_stocks ps ON p.product_id = ps.product_id
    LEFT JOIN warehouses w ON ps.warehouse_id = w.warehouse_id
    LEFT JOIN locations loc ON ps.location_id = loc.location_id
    WHERE 1=1
";

$params = [];
$conditions = ["p.is_service = 0"];

// Apply filters
if ($status_filter != 'all') {
    $conditions[] = "p.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($search)) {
    $conditions[] = "(
        p.product_name LIKE :search OR 
        p.sku LIKE :search OR 
        p.barcode LIKE :search OR 
        p.description LIKE :search OR 
        c.category_name LIKE :search OR 
        b.brand_name LIKE :search
    )";
    $params[':search'] = "%$search%";
}

if ($category_id > 0) {
    // Include subcategories
    $subcategories = get_subcategories($pdo, $category_id);
    $category_ids = array_merge([$category_id], $subcategories);
    
    $cat_placeholders = [];
    foreach ($category_ids as $idx => $id) {
        $placeholder = ":cat_$idx";
        $cat_placeholders[] = $placeholder;
        $params[$placeholder] = $id;
    }
    
    $conditions[] = "p.category_id IN (" . implode(',', $cat_placeholders) . ")";
}

if ($supplier_id > 0) {
    $conditions[] = "p.supplier_id = :supplier_id";
    $params[':supplier_id'] = $supplier_id;
}

if ($brand_id > 0) {
    $conditions[] = "p.brand_id = :brand_id";
    $params[':brand_id'] = $brand_id;
}

if ($min_price > 0) {
    $conditions[] = "p.selling_price >= :min_price";
    $params[':min_price'] = $min_price;
}

if ($max_price > 0) {
    $conditions[] = "p.selling_price <= :max_price";
    $params[':max_price'] = $max_price;
}

if ($low_stock === 'yes') {
    $conditions[] = "COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= p.min_stock_level";
    $conditions[] = "p.min_stock_level > 0";
}

// Project scope: NULL = global (visible to all); set = only users assigned to that project.
// Carries its own leading AND, so it is appended to each query rather than pushed
// into $conditions (which are joined with " AND ").
$scope_sql = scopeFilterSqlNullable('project', 'p');

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}
$query .= $scope_sql;

// Group by product
$query .= " GROUP BY p.product_id";

// Attention filter (aggregate → must be HAVING). Mirrors dashboard alert logic:
// low/out stock, negative stock, or expiring within 30 days.
if ($attention) {
    $query .= " HAVING (
        (COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= p.min_stock_level AND p.min_stock_level > 0)
        OR COALESCE(SUM(ps.stock_quantity - ps.reserved_quantity), 0) <= 0
        OR (p.expiry_date IS NOT NULL AND p.expiry_date > CURDATE() AND DATEDIFF(p.expiry_date, CURDATE()) <= 30)
    )";
}

// Apply sorting
$valid_sort_columns = [
    'product_name', 'sku', 'selling_price', 'cost_price', 
    'total_stock', 'available_stock', 'sales_last_30_days',
    'created_at', 'updated_at', 'markup_percentage'
];

if (in_array($sort_by, $valid_sort_columns)) {
    $sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';
    $query .= " ORDER BY $sort_by $sort_order";
} else {
    $query .= " ORDER BY p.created_at DESC";
}

// Add pagination
$query .= " LIMIT :limit OFFSET :offset";

// Get total count for pagination
$count_query = "SELECT COUNT(DISTINCT p.product_id) as total FROM products p";
if ($category_id > 0 || !empty($search)) {
    $count_query .= " LEFT JOIN categories c ON p.category_id = c.category_id";
}
if ($brand_id > 0 || !empty($search)) {
    $count_query .= " LEFT JOIN brands b ON p.brand_id = b.brand_id";
}
$count_query .= " WHERE 1=1";
if (!empty($conditions)) {
    $count_query .= " AND " . implode(" AND ", $conditions);
}
$count_query .= $scope_sql;

// Execute count query
$count_stmt = $pdo->prepare($count_query);
foreach ($params as $key => $value) {
    if (is_int($value)) {
        $count_stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $count_stmt->bindValue($key, $value);
    }
}
$count_stmt->execute();
$total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_count / $per_page);

// Execute main query
$stmt = $pdo->prepare($query);

// Bind parameters for main query
foreach ($params as $key => $value) {
    if (is_int($value)) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Attention mode returns every match on one page — the base count query does not
// apply the HAVING filter, so derive the true total from the fetched rows.
if ($attention) {
    $total_count = count($products);
    $total_pages = 1;
}

// Get data for filter dropdowns
$categories = $pdo->query("SELECT category_id, category_name FROM categories WHERE status = 'active' AND type = 'product' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
$brands = $pdo->query("SELECT brand_id, brand_name FROM brands WHERE status = 'active' ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);
$tax_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status = 'active' ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);
$warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Measurement units from DB
$units = $pdo->query("SELECT unit_code, unit_name FROM product_units WHERE status = 'active' ORDER BY unit_name ASC")->fetchAll(PDO::FETCH_ASSOC);
if (empty($units)) {
    $units = [['unit_code' => 'pcs', 'unit_name' => 'Pieces']];
}

// Calculate global statistics for filtered results
$stats_query = "
    SELECT 
        COUNT(DISTINCT p.product_id) as total_products,
        SUM(p.cost_price * COALESCE(stock_summary.total_stock, 0)) as total_value,
        SUM(CASE 
            WHEN COALESCE(stock_summary.available_stock, 0) <= 0 THEN 1 
            ELSE 0 
        END) as out_of_stock_count,
        SUM(CASE 
            WHEN COALESCE(stock_summary.available_stock, 0) <= p.min_stock_level 
            AND p.min_stock_level > 0 THEN 1 
            ELSE 0 
        END) as low_stock_count,
        SUM(CASE WHEN p.status = 'inactive' THEN 1 ELSE 0 END) as inactive_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    LEFT JOIN brands b ON p.brand_id = b.brand_id
    LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id
    LEFT JOIN (
        SELECT 
            ps2.product_id as agg_pid,
            SUM(ps2.stock_quantity) as total_stock,
            SUM(ps2.stock_quantity - ps2.reserved_quantity) as available_stock
        FROM product_stocks ps2
        GROUP BY ps2.product_id
    ) as stock_summary ON p.product_id = stock_summary.agg_pid
";

$stats_query .= " WHERE 1=1";
if (!empty($conditions)) {
    $stats_query .= " AND " . implode(" AND ", $conditions);
}
$stats_query .= $scope_sql;

// Reuse the $params from before
$stats_stmt = $pdo->prepare($stats_query);
foreach ($params as $key => $value) {
    if (is_int($value)) $stats_stmt->bindValue($key, $value, PDO::PARAM_INT);
    else $stats_stmt->bindValue($key, $value);
}
$stats_stmt->execute();
$all_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$total_products = $all_stats['total_products'] ?? 0;
$total_value = $all_stats['total_value'] ?? 0;
$low_stock_count = $all_stats['low_stock_count'] ?? 0;
$out_of_stock_count = $all_stats['out_of_stock_count'] ?? 0;
$inactive_count = $all_stats['inactive_count'] ?? 0;

// Helper functions
function get_subcategories($pdo, $parent_id) {
    $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE parent_id = ? AND status = 'active'");
    $stmt->execute([$parent_id]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    $all_subcategories = [];
    foreach ($subcategories as $subcat) {
        $all_subcategories[] = $subcat;
        $all_subcategories = array_merge($all_subcategories, get_subcategories($pdo, $subcat));
    }
    
    return $all_subcategories;
}
// Helper functions removed, now in helpers.php
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

function get_markup_color($percentage) {
    if ($percentage >= 50) return 'text-success';
    if ($percentage >= 20) return 'text-warning';
    return 'text-danger';
}

// safe_output removed, now in helpers.php

// format_date removed, now in helpers.php

function get_quick_actions($product) {
    global $can_edit_products, $can_delete_products, $can_adjust_stock;
    
    $actions = [];
    
    if ($can_edit_products) {
        $actions[] = '<a href="' . getUrl('product_edit') . '?id=' . $product['product_id'] . '" class="dropdown-item">
                        <i class="bi bi-pencil"></i> Edit Product
                      </a>';
    }
    
    if ($can_adjust_stock) {
        $actions[] = '<a href="#" class="dropdown-item" onclick="adjustStock(' . $product['product_id'] . ')">
                        <i class="bi bi-box-arrow-in-down"></i> Adjust Stock
                      </a>';
    }
    
    if ($can_edit_products) {
        $actions[] = '<a href="#" class="dropdown-item" onclick="duplicateProduct(' . $product['product_id'] . ')">
                        <i class="bi bi-copy"></i> Duplicate Product
                      </a>';
    }
    
    if ($can_adjust_stock) {
        $actions[] = '<a href="stock_transfers.php?product=' . $product['product_id'] . '" class="dropdown-item">
                        <i class="bi bi-arrow-left-right"></i> Transfer Stock
                      </a>';
    }
    
    if ($can_edit_products) {
        $actions[] = '<a href="purchase_order_create.php?product=' . $product['product_id'] . '" class="dropdown-item">
                        <i class="bi bi-truck"></i> Order More
                      </a>';
    }
    
    if ($can_delete_products && $product['status'] != 'active') {
        $actions[] = '<div class="dropdown-divider"></div>';
        $actions[] = '<a href="#" class="dropdown-item text-danger" onclick="deleteProduct(' . $product['product_id'] . ')">
                        <i class="bi bi-trash"></i> Delete Product
                      </a>';
    }
    
    return implode('', $actions);
}
?>

<div class="container-fluid mt-2 mt-md-4 px-2 px-md-4">
    <!-- Page Header -->
    <div class="row mb-3 mb-md-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-start flex-nowrap gap-2">
                <div>
                    <h2 class="mb-0 fs-4 fs-md-2 fw-bold"><i class="bi bi-box"></i> Product Management</h2>
                    <p class="text-muted mb-0 d-none d-md-block small mt-1">Manage your inventory, prices, and stock levels</p>
                </div>
                <div class="ms-auto flex-shrink-0 pt-1 pt-md-2">
                    <?php if ($can_create_products): ?>
                    <button type="button" class="btn btn-primary btn-sm px-1 px-md-2 shadow-sm" style="border-radius: 6px;" onclick="openAddProductModal('inventory')" type="button">
                        <i class="bi bi-plus-circle"></i> <span class="d-none d-sm-inline">Add New Product</span><span class="d-inline d-sm-none text-uppercase fw-bold" style="font-size: 0.7rem;">Add New</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="d-md-none mt-2 mb-0 opacity-25">
        </div>
    </div>

   
   <!-- Print Only Header -->
<div class="d-none d-print-block">
    <div style="text-align:center; padding: 10px 0; border-bottom: 3px solid #0d6efd; margin-bottom: 10px;" class="mb-print-1 mt-0">

        

        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">
            Official Products Inventory Report
        </h2>

        <p style="color: #000; margin: 0; font-size: 10pt;">
            Report Date: <?= date('d M Y, H:i') ?>
        </p>

    </div>
</div>

    <!-- Statistics Cards -->
    <style>
        .custom-stat-card {
            background-color: #d1e7dd !important;
            border-color: #badbcc !important;
            transition: transform 0.2s;
            border-radius: 12px;
        }
        .custom-stat-card:hover { transform: translateY(-3px); }
        .custom-stat-card h3, 
        .custom-stat-card h4, 
        .custom-stat-card p, 
        .custom-stat-card i,
        .custom-stat-card .small {
            color: #0f5132 !important;
        }
    </style>
    <style>
    @media (max-width: 767px) {
        .main-header, .navbar, nav.navbar {
            position: sticky;
            top: 0;
            z-index: 1020;
            background: #fff;
        }
    }
    </style>
    <script>
        function resizeTextToFit() {
            const elements = document.querySelectorAll('.custom-stat-card h4.auto-resize');
            elements.forEach(el => {
                let size = 1.3; // Starting size
                el.style.fontSize = size + 'rem';
                
                // Get container width
                const containerWidth = el.closest('.overflow-hidden').clientWidth;
                
                // Reduce size until it fits or reaches minimum
                while (el.scrollWidth > containerWidth && size > 0.7) {
                    size -= 0.05;
                    el.style.fontSize = size + 'rem';
                }
            });
        }

        // Run on load and resize
        window.addEventListener('load', resizeTextToFit);
        window.addEventListener('resize', resizeTextToFit);
    </script>
    <?php if ($attention): ?>
    <div class="alert border-0 shadow-sm d-flex flex-wrap align-items-center gap-2 mb-4 d-print-none" style="background:#fff9e6; border-left:5px solid #ffc107 !important; border-radius:10px;">
        <i class="bi bi-funnel-fill fs-5 text-warning"></i>
        <div class="flex-grow-1">
            <strong>Showing only items that need attention</strong>
            <span class="text-muted small d-block">Low / out / negative stock, or expiring within 30 days — <?= (int)$total_count ?> item(s).</span>
        </div>
        <a href="<?= getUrl('products') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i> Show all products</a>
    </div>
    <?php endif; ?>
    <div class="row mb-4" id="print-stats-cards">
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-box"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Total Products</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= $total_products ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-cash-stack"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Current Value</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= format_currency($total_value) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Low Stock</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= $low_stock_count ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-x-circle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Out of Stock</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= $out_of_stock_count ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form method="GET" action="" class="row g-2 g-md-3" id="filterForm">
                    <!-- Search moved to Actions Bar -->
                    <input type="hidden" name="search" id="hiddenSearch" value="<?= safe_output($search) ?>">
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold">Category</label>
                        <select class="form-select form-select-sm select2-static" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['category_id'] ?>" 
                                    <?= $category_id == $category['category_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold">Brand</label>
                        <select class="form-select form-select-sm select2-static" name="brand">
                            <option value="">All Brands</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= $brand['brand_id'] ?>" 
                                    <?= $brand_id == $brand['brand_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($brand['brand_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold">Supplier</label>
                        <select class="form-select form-select-sm select2-static" name="supplier">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= $supplier['supplier_id'] ?>" 
                                    <?= $supplier_id == $supplier['supplier_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($supplier['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold">Status</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            <option value="discontinued" <?= $status_filter == 'discontinued' ? 'selected' : '' ?>>Discontinued</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-bold">Stock Status</label>
                        <select class="form-select form-select-sm" name="low_stock">
                            <option value="">All Stock</option>
                            <option value="yes" <?= $low_stock == 'yes' ? 'selected' : '' ?>>Low Stock Only</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2 d-flex align-items-end gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1" style="height: 31px;" title="Apply Filters">
                            <i class="bi bi-funnel"></i> Filter
                        </button>
                        <a href="products.php" class="btn btn-outline-secondary btn-sm" style="height: 31px;" title="Reset Filters">
                            <i class="bi bi-arrow-clockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap flex-grow-1">
                    <!-- Buttons group: full-width on mobile, auto on desktop -->
                    <div class="d-flex shadow-sm bg-white product-action-btns" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                        <button type="button" class="btn btn-white fw-medium border-0 text-center px-2 px-md-3 py-2" onclick="copyTable()" style="background: #fff; color: #444; min-width: 0;">
                            <i class="bi bi-clipboard text-info me-1" style="font-size: 0.9rem;"></i><span style="font-size: 0.78rem;">Copy</span>
                        </button>
                        <div style="width: 1px; background: #eee; height: 24px; margin-top: 8px;"></div>
                        <button type="button" class="btn btn-white fw-medium border-0 text-center px-2 px-md-3 py-2" onclick="exportProducts()" style="background: #fff; color: #444; min-width: 0;">
                            <i class="bi bi-file-earmark-spreadsheet text-success me-1" style="font-size: 0.9rem;"></i><span style="font-size: 0.78rem;">CSV</span>
                        </button>
                        <div style="width: 1px; background: #eee; height: 24px; margin-top: 8px;"></div>
                        <button type="button" class="btn btn-white fw-medium border-0 text-center px-2 px-md-3 py-2" onclick="printTable()" style="background: #fff; color: #444; min-width: 0;">
                            <i class="bi bi-printer text-primary me-1" style="font-size: 0.9rem;"></i><span style="font-size: 0.78rem;">Print</span>
                        </button>
                        <div style="width: 1px; background: #eee; height: 24px; margin-top: 8px;"></div>
                        <a href="<?= getUrl('reports') ?>?report=inventory" class="btn btn-white fw-medium border-0 text-center px-2 px-md-3 py-2" style="background: #fff; color: #444; min-width: 0; text-decoration: none;">
                            <i class="bi bi-graph-up text-warning me-1" style="font-size: 0.9rem;"></i><span style="font-size: 0.78rem;">Reports</span>
                        </a>
                    </div>

                    <!-- Show + Search: flex row, search grows -->
                    <div class="d-flex align-items-center gap-2 flex-grow-1 flex-nowrap">
                        <div class="d-flex align-items-center bg-white shadow-sm px-2 px-sm-3 py-1 flex-shrink-0" style="border: 1px solid #dee2e6; border-radius: 8px; height: 38px;">
                            <span class="small text-muted me-1 me-sm-2 text-nowrap"><i class="bi bi-list-ol d-none d-sm-inline"></i> Show:</span>
                            <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 45px; box-shadow: none; background: transparent;" onchange="updatePerPage(this.value)">
                                <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
                                <option value="25" <?= $per_page == 25 ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
                            </select>
                        </div>
                        <div class="input-group input-group-sm shadow-sm flex-grow-1" style="border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6; height: 38px; min-width: 120px; max-width: 280px;">
                            <span class="input-group-text bg-white border-0 px-2"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" class="form-control border-0 p-2" id="productSearch" value="<?= safe_output($search) ?>" placeholder="Search..." onkeyup="if(event.key === 'Enter') handleSearch(this.value)">
                        </div>
                    </div>

                    <!-- View Toggle Buttons (Repositioned to Right) -->
                    <div class="btn-group shadow-sm ms-md-auto d-none d-md-flex" role="group">
                        <button type="button" class="btn btn-primary btn-sm text-white" id="btn-table-view" onclick="toggleView('table')" title="Table View" style="height: 38px; width: 40px;">
                            <i class="bi bi-table"></i>
                        </button>
                        <button type="button" class="btn btn-light btn-sm" id="btn-card-view" onclick="toggleView('card')" title="Card View" style="height: 38px; width: 40px;">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill shadow-sm d-none d-md-inline-block">
                        <i class="bi bi-check-circle-fill me-1"></i> <?= $total_count ?> records
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div id="tableView" class="view-section">
        <div class="card border-0 shadow-sm overflow-hidden">
            <div class="card-header bg-white border-bottom py-3">
                <h5 class="mb-0 fw-bold">Products List</h5>
            </div>
        <div class="card-body p-0 p-md-3">
            <div id="form-message" class="mb-3 px-3 px-md-0"></div>
            
            <?php if (count($products) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0" id="productsTable" style="width: 100% !important;">
                        <thead class="table-light">
                            <tr>
                                <th class="px-2 px-md-3" width="5%">S/NO:</th>
                                <th width="30%">Product</th>
                                <th width="12%">SKU</th>
                                <th width="15%">Category</th>
                                <th width="12%">Stock</th>
                                <th width="13%">Price</th>
                                <th class="text-end px-3" width="13%">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $index => $product): 
                                $row_number = $offset + $index + 1;
                                $available_stock = $product['available_stock'];
                                $is_critical = $product['stock_status'] == 'out_of_stock';
                            ?>
                            <tr>
                                <td class="text-center"><span class="text-muted fw-bold"><?= $row_number ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="product-img-wrapper me-3">
                                            <?php if (!empty($product['image_url'])): ?>
                                            <img src="<?= safe_output($product['image_url']) ?>" class="rounded shadow-sm">
                                            <?php else: ?>
                                            <div class="product-placeholder rounded shadow-sm">
                                                <i class="bi bi-box"></i>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark"><?= safe_output($product['product_name']) ?></div>
                                            <small class="text-muted"><?= safe_output($product['brand_name'] ?? 'No Brand') ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <code class="custom-code"><?= safe_output($product['sku']) ?></code>
                                </td>
                                <td>
                                    <?php if (!empty($product['category_name'])): ?>
                                    <span class="badge bg-light text-dark border"><?= safe_output($product['category_name']) ?></span>
                                    <?php else: ?>
                                    <span class="text-muted small">No Category</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($product['stock_status'] == 'out_of_stock'): ?>
                                    <span class="text-danger fw-bold"><i class="bi bi-x-circle-fill"></i> Out of Stock</span>
                                    <?php elseif ($product['stock_status'] == 'low_stock'): ?>
                                    <span class="text-warning fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> <?= $available_stock ?> (Low)</span>
                                    <?php else: ?>
                                    <span class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> <?= $available_stock ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold text-dark"><?= format_currency($product['selling_price']) ?></span>
                                </td>
                                <td class="text-end px-3">
                                    <div class="dropdown text-end">
                                        <button class="btn btn-sm btn-light border dropdown-toggle px-2" type="button" data-bs-toggle="dropdown" style="border-radius: 6px;">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                                            <li><a class="dropdown-item" href="<?= getUrl('products/view') ?>?id=<?= $product['product_id'] ?>"><i class="bi bi-eye text-primary"></i> View Details</a></li>
                                            <?php if ($can_edit_products): ?>
                                            <li><a class="dropdown-item" href="<?= getUrl('product_edit') ?>?id=<?= $product['product_id'] ?>"><i class="bi bi-pencil text-warning"></i> Edit Product</a></li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_adjust_stock): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="adjustStock(<?= $product['product_id'] ?>); return false;">
                                                    <i class="bi bi-box-arrow-in-down text-info"></i> Adjust Stock
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('stock_transfers') ?>?product=<?= $product['product_id'] ?>">
                                                    <i class="bi bi-arrow-left-right text-info"></i> Transfer Stock
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_products): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="duplicateProduct(<?= $product['product_id'] ?>); return false;">
                                                    <i class="bi bi-copy"></i> Duplicate Product
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('purchase_order_create') ?>?product=<?= $product['product_id'] ?>">
                                                    <i class="bi bi-truck"></i> Create Purchase Order
                                                </a>
                                            </li>
                                            <!-- <li>
                                                <a class="dropdown-item" href="#" onclick="printBarcode(<?= $product['product_id'] ?>); return false;">
                                                    <i class="bi bi-upc-scan"></i> Print Barcode
                                                </a>
                                            </li> -->
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_products): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php if ($product['status'] == 'active'): ?>
                                            <li>
                                                <a class="dropdown-item text-warning" href="#" 
                                                   onclick="changeStatus(<?= $product['product_id'] ?>, 'inactive'); return false;">
                                                    <i class="bi bi-slash-circle"></i> Deactivate Product
                                                </a>
                                            </li>
                                            <?php else: ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" 
                                                   onclick="changeStatus(<?= $product['product_id'] ?>, 'active'); return false;">
                                                    <i class="bi bi-check-circle"></i> Activate Product
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_delete_products && $product['status'] == 'inactive'): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" 
                                                   onclick="deleteProduct(<?= $product['product_id'] ?>); return false;">
                                                    <i class="bi bi-trash"></i> Delete Product
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination and Showing Entries -->
                <div class="row align-items-center py-3 px-3 border-top g-0">
                    <div class="col-12">
                        <?php if ($total_pages > 1): ?>
                        <nav aria-label="Product pagination">
                            <ul class="pagination pagination-sm justify-content-end mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= get_pagination_url(1) ?>">
                                        <i class="bi bi-chevron-double-left"></i>
                                    </a>
                                </li>
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= get_pagination_url($page - 1) ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= get_pagination_url($i) ?>"><?= $i ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= get_pagination_url($page + 1) ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= get_pagination_url($total_pages) ?>">
                                        <i class="bi bi-chevron-double-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div> <!-- end tableView -->

    <!-- Card View Container -->
    <div id="cardView" class="view-section d-none">
        <?php if (count($products) > 0): ?>
            <div class="row g-3">
                <?php foreach ($products as $product): 
                    $available_stock = $product['available_stock'];
                    $stock_status = $product['stock_status'];
                ?>
                <div class="col-12 col-md-6 col-lg-4 col-xl-3">
                    <div class="card h-100 shadow-sm border-light">
                        <div class="position-relative">
                            <div class="product-card-img-wrapper" style="height: 160px; overflow: hidden; background: #f8f9fa;">
                                <?php if (!empty($product['image_url'])): ?>
                                <img src="<?= safe_output($product['image_url']) ?>" class="card-img-top w-100 h-100" style="object-fit: contain;">
                                <?php else: ?>
                                <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted">
                                    <i class="bi bi-image" style="font-size: 3rem;"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="position-absolute top-0 end-0 p-2">
                                <?php if ($stock_status == 'out_of_stock'): ?>
                                <span class="badge bg-danger">Out of Stock</span>
                                <?php elseif ($stock_status == 'low_stock'): ?>
                                <span class="badge bg-warning text-dark">Low Stock</span>
                                <?php else: ?>
                                <span class="badge bg-success">In Stock</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="flex-grow-1 overflow-hidden">
                                    <h6 class="fw-bold mb-0 text-truncate" title="<?= safe_output($product['product_name']) ?>"><?= safe_output($product['product_name']) ?></h6>
                                    <small class="text-muted d-block text-truncate"><?= safe_output($product['category_name'] ?? 'General') ?></small>
                                </div>
                                <div class="text-end ms-2">
                                    <div class="fw-bold text-primary small"><?= format_currency($product['selling_price']) ?></div>
                                    <code class="small text-muted" style="font-size: 0.65rem;"><?= safe_output($product['sku']) ?></code>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between small mb-3">
                                <span class="text-muted">Stock:</span>
                                <span class="fw-bold <?= $stock_status == 'out_of_stock' ? 'text-danger' : ($stock_status == 'low_stock' ? 'text-warning' : 'text-success') ?>">
                                    <?= $available_stock ?> <?= safe_output($product['unit'] ?? 'pcs') ?>
                                </span>
                            </div>
                            <div class="d-flex gap-1 mt-auto">
                                <a href="<?= getUrl('products/view') ?>?id=<?= $product['product_id'] ?>" class="btn btn-sm btn-outline-primary flex-grow-1 shadow-sm"><i class="bi bi-eye"></i> View</a>
                                <?php if ($can_edit_products): ?>
                                <a href="<?= getUrl('product_edit') ?>?id=<?= $product['product_id'] ?>" class="btn btn-sm btn-outline-warning flex-grow-1 shadow-sm"><i class="bi bi-pencil"></i> Edit</a>
                                <?php endif; ?>
                                <?php if ($can_delete_products): ?>
                                <button class="btn btn-sm btn-outline-danger shadow-sm px-2" onclick="deleteProduct(<?= $product['product_id'] ?>); return false;" title="Delete Product">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Card View Pagination -->
            <div class="d-flex justify-content-center mt-4">
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Product pagination">
                    <ul class="pagination pagination-sm shadow-sm">
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                            <a class="page-link" href="<?= get_pagination_url($i) ?>"><?= $i ?></a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="text-center py-5 bg-white rounded shadow-sm">
                <i class="bi bi-box text-muted" style="font-size: 3rem;"></i>
                <p class="mt-3 text-muted">No products found matching your filters.</p>
                <a href="products.php" class="btn btn-primary btn-sm">Clear All Filters</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="stockAdjustmentModal" tabindex="-1" aria-labelledby="stockAdjustmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="stockAdjustmentModalLabel">
                    <i class="bi bi-box-arrow-in-down"></i> Adjust Stock
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="stockAdjustmentForm">
                    <input type="hidden" id="adjust_product_id" name="product_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" id="adjust_product_name" readonly>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">Current Stock</label>
                            <input type="text" class="form-control" id="current_stock" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Available Stock</label>
                            <input type="text" class="form-control" id="available_stock" readonly>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">Adjustment Type</label>
                            <select class="form-select" id="adjustment_type" name="movement_type" required>
                                <option value="">Select Type</option>
                                <option value="adjustment_in">Add Stock</option>
                                <option value="adjustment_out">Remove Stock</option>
                                <option value="set">Set Stock Level</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Quantity</label>
                            <input type="number" class="form-control" id="adjustment_quantity" 
                                   name="quantity" min="0.001" step="0.001" required>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">Warehouse</label>
                            <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                                <option value="">Select Warehouse</option>
                                <!-- Warehouses will be loaded via AJAX -->
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Reason</label>
                            <select class="form-select" id="adjustment_reason" name="reason" required>
                                <option value="">Select Reason</option>
                                <option value="damaged">Damaged Goods</option>
                                <option value="expired">Expired Products</option>
                                <option value="found">Found Stock</option>
                                <option value="theft">Theft/Loss</option>
                                <option value="correction">Stock Correction</option>
                                <option value="purchase_return">Purchase Return</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="adjustment_notes" name="notes" rows="2"></textarea>
                    </div>
                    
                    <div class="alert alert-info" id="new_stock_info" style="display: none;">
                        <strong>New Stock Level: <span id="new_stock_level">0</span></strong>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitStockAdjustment()">Save Adjustment</button>
            </div>
        </div>
    </div>
</div>



<script>
// function to update per_page
function updatePerPage(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', val);
    url.searchParams.set('page', 1);
    window.location.href = url.href;
}

$(document).ready(function() {
    logReportAction('Viewed Products List', 'User viewed the products list page');
    
    $('#filterForm').on('submit', function() {
        logReportAction('Filtered Products List', 'User applied filters/search to the products list');
    });

    // Select2 — filter bar (outside modal)
    $('#filterForm .select2-static').each(function() {
        $(this).select2({ theme: 'bootstrap-5', placeholder: 'Select...', allowClear: true, width: '100%' });
    });

    // Select2 — modal selects (init on open, destroy on close)
    $('#addProductModal').on('shown.bs.modal', function() {
        $('#addProductModal .select2-static').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#addProductModal'), placeholder: 'Select...', allowClear: true, width: '100%' });
            }
        });
    }).on('hidden.bs.modal', function() {
        $('#addProductModal .select2-static').each(function() {
            if ($(this).hasClass('select2-hidden-accessible')) { $(this).select2('destroy'); }
        });
    });

    // Initialize DataTable
    $('#productsTable').DataTable({
        language: {
            search: "Quick Search:",
            info: false,
            paginate: {
                next: '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        },
        pageLength: <?= $per_page ?>,
        order: [], // Disable initial sorting to maintain our custom sort
        dom: 'rt<"d-flex justify-content-end align-items-center mt-3"p>', // Hide internal info, length menu and search since we have custom ones
        responsive: false,
        scrollX: false
    });
    
    // Real-time stock update simulation
    setInterval(function() {
        updateStockCounts();
    }, 30000); // Update every 30 seconds
});

function printBarcode(productId) {
    logReportAction('Printed Product Barcode', 'User generated barcodes for product ID: ' + productId);
    window.open(`print_barcode.php?product_id=${productId}&quantity=10`, '_blank');
}

function adjustStock(productId) {
    $.ajax({
        url: 'api/get_product_stock.php',
        type: 'GET',
        data: { product_id: productId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const product = response.data;
                $('#adjust_product_id').val(product.product_id);
                $('#adjust_product_name').val(product.product_name);
                $('#current_stock').val(product.total_stock);
                $('#available_stock').val(product.available_stock);
                
                // Load warehouses
                loadWarehouses(productId);
                
                $('#stockAdjustmentModal').modal('show');
                logReportAction('Viewed Adjust Stock Modal', 'User opened stock adjustment modal for product: ' + product.product_name);
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
            }
        }
    });
}

function loadWarehouses(productId) {
    $.ajax({
        url: 'api/get_product_warehouses.php',
        type: 'GET',
        data: { product_id: productId },
        dataType: 'json',
        success: function(response) {
            const select = $('#warehouse_id');
            select.empty();
            select.append('<option value="">Select Warehouse</option>');
            
            if (response.success) {
                response.data.forEach(warehouse => {
                    select.append(`<option value="${warehouse.warehouse_id}">
                        ${warehouse.warehouse_name} (Stock: ${warehouse.stock_quantity})
                    </option>`);
                });
            }
        }
    });
}

// Calculate new stock level
$('#adjustment_type, #adjustment_quantity').on('change keyup', function() {
    const type = $('#adjustment_type').val();
    const quantity = parseFloat($('#adjustment_quantity').val()) || 0;
    const current = parseFloat($('#current_stock').val()) || 0;
    
    let newStock = current;
    
    switch (type) {
        case 'adjustment_in':
            newStock = current + quantity;
            break;
        case 'adjustment_out':
            newStock = current - quantity;
            break;
        case 'set':
            newStock = quantity;
            break;
    }
    
    if (type) {
        $('#new_stock_level').text(newStock.toFixed(3));
        $('#new_stock_info').show();
    } else {
        $('#new_stock_info').hide();
    }
});

function submitStockAdjustment() {
    const formData = $('#stockAdjustmentForm').serialize();
    
    if (!$('#warehouse_id').val()) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Information',
            text: 'Please select a warehouse.'
        });
        return;
    }
    
    $.ajax({
        url: 'api/adjust_stock.php',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                logReportAction('Adjusted Stock', 'User adjusted stock for ' + $('#adjust_product_name').val() + ' (' + $('#adjustment_type').val() + ' ' + $('#adjustment_quantity').val() + ')');
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'OK'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: response.message
                });
            }
        }
    });
}

function changeStatus(productId, newStatus) {
    const action = newStatus === 'active' ? 'activate' : 'deactivate';
    const actionText = newStatus === 'active' ? 'Activate' : 'Deactivate';
    
    Swal.fire({
        title: `${actionText} Product?`,
        text: `Are you sure you want to ${action} this product?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Yes, ${actionText}`,
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/update_product_status.php',
                type: 'POST',
                data: { 
                    product_id: productId,
                    status: newStatus
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Changed Product Status', 'User changed status of product ID ' + productId + ' to ' + newStatus);
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: response.message,
                            confirmButtonColor: '#28a745',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                }
            });
        }
    });
}

function deleteProduct(productId) {
    Swal.fire({
        title: 'Delete Product',
        text: 'Are you sure you want to delete this product? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel'
    }).then((result) => {

        if (result.isConfirmed) {
            $.ajax({
                url: 'api/delete_product.php',
                type: 'POST',
                data: { product_id: productId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Deleted Product', 'User deleted product ID ' + productId);
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Product has been deleted.',
                            confirmButtonColor: '#28a745',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                }
            });
        }
    });
}

function duplicateProduct(productId) {
    Swal.fire({
        title: 'Duplicate Product',
        text: 'Create a copy of this product?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Duplicate',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/duplicate_product.php',
                type: 'POST',
                data: { product_id: productId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        logReportAction('Duplicated Product', 'User duplicated product ID ' + productId);
                        Swal.fire({
                            icon: 'success',
                            title: 'Duplicated!',
                            text: 'Product duplicated successfully.',
                            confirmButtonColor: '#28a745',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            window.location.href = '<?= getUrl("product_edit") ?>?id=' + response.new_product_id;
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: response.message
                        });
                    }
                }
            });
        }
    });
}

// Helper to log actions

function exportProducts() {
    logReportAction('Exported Products list', 'Exported product records to Excel/CSV file');
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'api/export_products.php?' + params.toString();
}

function copyTable() {
    logReportAction('Copied Products list', 'Copied product records to clipboard');
    $('#productsTable').DataTable().button('.buttons-copy').trigger();
    Swal.fire({
        icon: 'success',
        title: 'Copied!',
        text: 'Product data copied to clipboard.',
        timer: 1500,
        showConfirmButton: false
    });
}

function printTable() {
    logReportAction('Printed Products list', 'Generated a printed report of the products list');
    window.print();
}

function updateStockCounts() {
    // Update low stock and out of stock badges
    $.ajax({
        url: 'api/get_stock_counts.php',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#lowStockCount').text(response.data.low_stock);
                $('#outOfStockCount').text(response.data.out_of_stock);
                
                // Highlight updates
                $('#lowStockCount').parent().addClass('highlight-update');
                $('#outOfStockCount').parent().addClass('highlight-update');
                
                setTimeout(() => {
                    $('#lowStockCount').parent().removeClass('highlight-update');
                    $('#outOfStockCount').parent().removeClass('highlight-update');
                }, 2000);
            }
        }
    });
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new product (when not in input field)
    if (e.ctrlKey && e.key === 'n' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        <?php if ($can_create_products): ?>
        window.location.href = 'product_create.php';
        <?php endif; ?>
    }
    
    // Ctrl + F to focus search
    if (e.ctrlKey && e.key === 'f' && !$(e.target).is('input, textarea, select')) {
        e.preventDefault();
        $('input[name="search"]').focus().select();
    }
    
    // F5 to refresh
    if (e.key === 'F5') {
        e.preventDefault();
        location.reload();
    }
});

// Auto-refresh data every 60 seconds

</script>

<style>
/* Stat Cards Styling */
.custom-stat-card {
    border-radius: 12px !important;
    transition: all 0.3s ease;
    background: #ffffff !important;
}

.custom-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.1) !important;
}

.stat-icon-wrapper {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
}

/* Actions Dropdown Styling */
.dropdown-menu {
    border: 1px solid rgba(0,0,0,0.05);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border-radius: 10px;
    padding: 8px;
}

.dropdown-item {
    border-radius: 6px;
    padding: 8px 16px;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.dropdown-item i {
    width: 20px;
    margin-right: 8px;
}

/* Table Enhancements */
.table thead th {
    background-color: #f8f9fa !important;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.8px;
    font-weight: 700;
    color: #6c757d;
    border-bottom: 2px solid #edf2f7;
    border-top: none;
    padding: 1rem 0.75rem;
}

.table tbody tr {
    transition: all 0.2s ease;
}

.table tbody tr:hover {
    background-color: #f8faff !important;
}

.table tbody td {
    vertical-align: middle;
    padding: 1rem 0.75rem;
    color: #4a5568;
    border-bottom: 1px solid #edf2f7;
}

/* Product Image Styles */
.product-img-wrapper {
    width: 44px;
    height: 44px;
    position: relative;
}

.product-img-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-placeholder {
    width: 100%;
    height: 100%;
    background-color: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #94a3b8;
    font-size: 1.2rem;
}

/* Custom Badges */
.badge {
    font-weight: 600;
    padding: 0.45em 0.85em;
    border-radius: 6px;
    font-size: 0.75rem;
}

.custom-code {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 0.85rem;
    color: #4a5568 !important;
    background-color: #f1f5f9;
    padding: 0.2rem 0.4rem;
    border-radius: 4px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
}

@media (max-width: 576px) {
    .col-xl-3, .col-md-6 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .dropdown-menu {
        position: fixed !important;
        top: auto !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        bottom: 60px !important;
    }
}

/* Button Group Width Control */
.product-action-btns {
    width: auto;
}

@media (max-width: 768px) {
    .product-action-btns {
        width: 100% !important;
    }
}
</style>
<!-- MARKER_START -->

<?php
function get_pagination_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'products.php?' . http_build_query($params);
}

function build_category_tree_modal($categories, $parent_id = 0, $depth = 0) {
    $html = '';
    foreach ($categories as $category) {
        if (($category['parent_id'] ?? 0) == $parent_id) {
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
            $html .= '<option value="' . $category['category_id'] . '">' 
                   . $indent . htmlspecialchars($category['category_name']) . '</option>';
            $html .= build_category_tree_modal($categories, $category['category_id'], $depth + 1);
        }
    }
    return $html;
}

function generate_sku_local() { return 'PROD' . time() . rand(100, 999); }
function generate_barcode_local() { 
    $barcode = '00' . rand(10000, 99999) . rand(10000, 99999);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) { $sum += ($i % 2 == 0) ? (int)$barcode[$i] : (int)$barcode[$i] * 3; }
    return $barcode . ((10 - ($sum % 10)) % 10);
}
?>

<!-- Add Product Modal -->

<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="addProductModalLabel">
                    <i class="bi bi-plus-circle me-2"></i> Add New Inventory Product
                    <span id="modal_header_inv_badge" class="badge bg-success bg-opacity-25 text-white ms-2 small">Inventory</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addProductForm" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" id="modal_is_service_inp"    name="is_service"      value="0">
                <input type="hidden" id="modal_track_inventory_inp" name="track_inventory" value="1">
                <div class="modal-body">
                    <div id="add-product-message" class="mb-3"></div>
                    
                    <style>
                        #productFormTabs { flex-wrap: nowrap; }
                        #productFormTabs .nav-item { flex: 1; }
                        #productFormTabs .nav-link { 
                            white-space: nowrap; 
                            padding: 0.4rem 0.3rem; 
                            font-size: 0.72rem;
                            text-align: center;
                            width: 100%;
                            overflow: hidden;
                            text-overflow: ellipsis;
                        }
                    </style>
                    <ul class="nav nav-tabs mb-4 flex-nowrap" id="productFormTabs" role="tablist">
                        <li class="nav-item flex-fill">
                            <button class="nav-link active fw-bold w-100" id="tab1-tab" data-bs-toggle="tab" data-bs-target="#tab1" type="button" role="tab"><i class="bi bi-info-circle d-block d-sm-inline mb-1 mb-sm-0 me-sm-1"></i><span style="font-size:0.7rem;">Basic Info</span></button>
                        </li>
                        <li class="nav-item flex-fill">
                            <button class="nav-link fw-bold w-100" id="tab2-tab" data-bs-toggle="tab" data-bs-target="#tab2" type="button" role="tab"><i class="bi bi-tag d-block d-sm-inline mb-1 mb-sm-0 me-sm-1"></i><span style="font-size:0.7rem;">Pricing</span></button>
                        </li>
                        <li class="nav-item flex-fill modal-inventory-only" id="tab3-nav-item">
                            <button class="nav-link fw-bold w-100" id="tab3-tab" data-bs-toggle="tab" data-bs-target="#tab3" type="button" role="tab"><i class="bi bi-box-seam d-block d-sm-inline mb-1 mb-sm-0 me-sm-1"></i><span style="font-size:0.7rem;">Inventory</span></button>
                        </li>
                        <li class="nav-item flex-fill">
                            <button class="nav-link fw-bold w-100" id="tab4-tab" data-bs-toggle="tab" data-bs-target="#tab4" type="button" role="tab"><i class="bi bi-card-list d-block d-sm-inline mb-1 mb-sm-0 me-sm-1"></i><span style="font-size:0.7rem;">Details</span></button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="productFormContent">
                        <!-- Tab 1: Basic Information -->
                        <div class="tab-pane fade show active" id="tab1" role="tabpanel">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label fw-bold">Product Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" name="product_name" required placeholder="e.g. iPhone 13 Pro">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">SKU</label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" name="sku" id="modal_sku" value="<?= generate_sku_local() ?>">
                                                <button class="btn btn-outline-secondary" type="button" onclick="refreshSKU()"><i class="bi bi-arrow-repeat"></i></button>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label fw-bold">Barcode</label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" name="barcode" id="modal_barcode" value="<?= generate_barcode_local() ?>">
                                                <button class="btn btn-outline-secondary" type="button" onclick="refreshBarcode()"><i class="bi bi-upc"></i></button>
                                            </div>
                                        </div>
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label fw-bold">Category</label>
                                            <select class="form-select select2-static" name="category_id" id="modal_category_id">
                                                <option value="">Select Category</option>
                                                <?= build_category_tree_modal($categories) ?>
                                            </select>
                                        </div>
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label fw-bold">Description</label>
                                            <textarea class="form-control" name="description" rows="3" placeholder="Detailed product description..."></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card bg-light border-0 mb-3">
                                        <div class="card-body text-center p-3">
                                            <label class="form-label fw-bold d-block mb-3">Product Image</label>
                                            <div class="image-preview-container border rounded p-2 bg-white mx-auto d-flex align-items-center justify-content-center position-relative" 
                                                 id="modal_image_preview_container" 
                                                 style="height: 180px; width: 100%; cursor: pointer;" 
                                                 onclick="document.getElementById('modal_product_image').click();">
                                                <div id="modal_image_placeholder">
                                                    <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
                                                    <p class="text-muted small mt-2 mb-0">Click to Upload</p>
                                                </div>
                                                <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-2 rounded-circle" 
                                                        style="z-index: 10;" 
                                                        onclick="event.stopPropagation(); removeModalImage()">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                                <input type="file" id="modal_product_image" name="product_image" class="d-none" onchange="previewProductImage(this)">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card bg-light border-0">
                                        <div class="card-body p-3">
                                            <label class="form-label fw-bold">Product Status</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="status" value="active" checked id="modal_status">
                                                <label class="form-check-label" for="modal_status">Active / For Sale</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 2: Pricing & Tax -->
                        <div class="tab-pane fade" id="tab2" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Cost Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">TZS</span>
                                        <input type="number" class="form-control" name="cost_price" id="modal_cost_price" value="0.00" step="0.01" required onkeyup="modalCalcMarkup()">
                                    </div>
                                    <small class="text-muted">The price you paid for this product</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Selling Price <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-success text-white">TZS</span>
                                        <input type="number" class="form-control fw-bold" name="selling_price" id="modal_selling_price" value="0.00" step="0.01" required onkeyup="modalCalcMarkup(); modalCalcMinPrice();">
                                    </div>
                                    <small class="text-muted">Final price at which you sell to customers</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Wholesale Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-info text-white">TZS</span>
                                        <input type="number" class="form-control" name="wholesale_price" value="0.00" step="0.01">
                                    </div>
                                    <small class="text-muted">Price for bulk/wholesale buyers</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Discount Rate (%)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="discount_rate" id="modal_discount_rate" value="0.00" step="0.01" onkeyup="modalCalcMinPrice()">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <small class="text-muted">Max discount allowed in POS</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Min Selling Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-danger text-white">TZS</span>
                                        <input type="number" class="form-control" name="min_selling_price" id="modal_min_selling_price" value="0.00" step="0.01">
                                    </div>
                                    <small class="text-muted">Auto-calculated but can be overridden</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Tax Rate</label>
                                    <select class="form-select select2-static" name="tax_id" id="modal_tax_id">
                                        <option value="">No Tax</option>
                                        <?php foreach ($tax_rates as $tax): ?>
                                            <option value="<?= $tax['rate_id'] ?>"><?= safe_output($tax['rate_name']) ?> (<?= $tax['rate_percentage'] ?>%)</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="is_taxable" value="1" checked id="modal_taxable">
                                        <label class="form-check-label small" for="modal_taxable">Calculate tax for this item</label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 bg-light rounded text-center h-100 d-flex flex-column justify-content-center">
                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Estimated Profit</small>
                                        <h5 class="fw-bold text-success mb-0" id="modal_profit_badge">TZS 0.00</h5>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="p-3 bg-light rounded text-center h-100 d-flex flex-column justify-content-center">
                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Gross Margin</small>
                                        <h5 class="fw-bold text-info mb-0" id="modal_markup_badge">0.00%</h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 3: Inventory & Stock -->
                        <div class="tab-pane fade modal-inventory-only" id="tab3" role="tabpanel">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label fw-bold">Measurement Unit</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="unit" id="modal_unit_input" list="unit_list" placeholder="e.g. pcs, kg, Box" onchange="updateModalUnit(this.value)">
                                        <datalist id="unit_list">
                                            <?php foreach ($units as $u): ?>
                                                <option value="<?= $u['unit_code'] ?>"><?= $u['unit_name'] ?></option>
                                            <?php endforeach; ?>
                                        </datalist>
                                        <button class="btn btn-outline-primary" type="button" onclick="showQuickAddUnit()" title="Add to Database">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Reorder Level</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="reorder_level" value="0">
                                        <span class="input-group-text modal-unit-label">pcs</span>
                                    </div>
                                    <small class="text-muted">Notify me when stock falls below this</small>
                                </div>
                                <div class="col-md-4 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Min Stock Level</label>
                                    <input type="number" class="form-control" name="min_stock_level" value="0">
                                </div>
                                <div class="col-md-4 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Max Stock Level</label>
                                    <input type="number" class="form-control" name="max_stock_level" value="0">
                                </div>

                                <div class="col-md-12 mt-3 p-3 bg-white border rounded modal-inventory-only">
                                    <h6 class="fw-bold border-bottom pb-2 mb-3 text-primary">
                                        <i class="bi bi-box-seam me-2"></i> OPENING STOCK (Current Inventory)
                                    </h6>
                                    <p class="text-muted small mb-3">Enter the current stock quantity for each warehouse/store below:</p>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover border">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Store / Warehouse Name</th>
                                                    <th style="width: 200px;" class="text-center">Available Quantity</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($warehouses)): ?>
                                                    <tr><td colspan="2" class="text-center p-3">No warehouses found. Please create one first.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($warehouses as $wh): ?>
                                                    <tr>
                                                        <td class="align-middle fw-semibold"><?= safe_output($wh['warehouse_name']) ?></td>
                                                        <td>
                                                            <div class="input-group input-group-sm">
                                                                <input type="number" class="form-control text-center" 
                                                                       name="initial_stock[<?= $wh['warehouse_id'] ?>]" value="" placeholder="0" min="0">
                                                                <span class="input-group-text modal-unit-label">pcs</span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab 4: Additional Details -->
                        <div class="tab-pane fade" id="tab4" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Brand</label>
                                    <select class="form-select select2-static" name="brand_id" id="modal_brand_id">
                                        <option value="">Select Brand</option>
                                        <?php foreach ($brands as $brand): ?>
                                            <option value="<?= $brand['brand_id'] ?>"><?= safe_output($brand['brand_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Preferred Supplier</label>
                                    <select class="form-select select2-static" name="supplier_id" id="modal_supplier_id">
                                        <option value="">Select Supplier</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?= $supplier['supplier_id'] ?>"><?= safe_output($supplier['supplier_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Weight (kg)</label>
                                    <input type="number" step="0.001" class="form-control" name="weight" placeholder="0.000">
                                </div>
                                <div class="col-md-8 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Dimensions (L x W x H)</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control text-center" name="dim_length" placeholder="L" inputmode="decimal">
                                        <span class="input-group-text">x</span>
                                        <input type="text" class="form-control text-center" name="dim_width" placeholder="W" inputmode="decimal">
                                        <span class="input-group-text">x</span>
                                        <input type="text" class="form-control text-center" name="dim_height" placeholder="H" inputmode="decimal">
                                        <span class="input-group-text">cm</span>
                                    </div>
                                    <small class="text-muted">Combined automatically as "LxWxH cm"</small>
                                </div>
                                <div class="col-md-4 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Warranty (Months)</label>
                                    <input type="number" class="form-control" name="warranty_period" placeholder="0">
                                </div>
                                <div class="col-md-8 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Manufacturer</label>
                                    <input type="text" class="form-control" name="manufacturer" placeholder="Manufacturer name">
                                </div>
                                <div class="col-md-6 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Model</label>
                                    <input type="text" class="form-control" name="model" placeholder="Model or series">
                                </div>
                                <div class="col-md-6 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Serial Number</label>
                                    <input type="text" class="form-control" name="serial_number" placeholder="Serial or IMEI">
                                </div>
                                <div class="col-md-4 mb-3 modal-inventory-only">
                                    <label class="form-label fw-bold">Expiry Days</label>
                                    <input type="number" class="form-control" name="expiry_days" value="0">
                                    <small class="text-muted">Shelf life in days</small>
                                </div>
                                
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <div class="ms-auto">
                        <button type="button" class="btn btn-outline-primary me-2 px-3" onclick="saveAddAnother()">
                            Save & Add Another
                        </button>
                        <button type="submit" class="btn btn-primary px-5 fw-bold">
                            <i class="bi bi-check-circle me-1"></i> Create Product
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
// ══ PRODUCT ADD MODAL ═══════════════════════════════════════
function openAddProductModal(type) {
    // Inventory is default for this page
    document.getElementById('modal_is_service_inp').value      = '0';
    document.getElementById('modal_track_inventory_inp').value = '1';

    // Update header badge
    var hdrInv = document.getElementById('modal_header_inv_badge');
    if (hdrInv) hdrInv.classList.remove('d-none');

    // Ensure all inventory elements are visible
    document.querySelectorAll('.modal-inventory-only').forEach(function(el) {
        el.style.display = '';
    });

    // Open addProductModal
    new bootstrap.Modal(document.getElementById('addProductModal')).show();
}

// Reset modal when closed
document.addEventListener('DOMContentLoaded', function() {
    var addModal = document.getElementById('addProductModal');
    if (addModal) {
        addModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('modal_is_service_inp').value      = '0';
            document.getElementById('modal_track_inventory_inp').value = '1';
            document.querySelectorAll('.modal-inventory-only').forEach(function(el) { el.style.display = ''; });
        });
    }
});

function refreshSKU() { 
    logReportAction('Generated Random SKU', 'User refreshed the SKU in the create product modal');
    document.getElementById('modal_sku').value = 'PROD' + Date.now().toString().slice(-8) + Math.floor(Math.random()*100); 
}
function refreshBarcode() { 
    logReportAction('Generated Random Barcode', 'User refreshed the Barcode in the create product modal');
    document.getElementById('modal_barcode').value = '69' + (Math.floor(Math.random() * 9000000000) + 1000000000); 
}
function previewProductImage(input) { 
    if (input.files && input.files[0]) { 
        let reader = new FileReader(); 
        reader.onload = function(e) { 
            let placeholder = document.getElementById('modal_image_placeholder');
            if (placeholder) placeholder.style.display = 'none';
            
            let preview = document.getElementById('modal_image_preview_img');
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'modal_image_preview_img';
                preview.className = 'img-fluid rounded-4 h-100 w-100';
                preview.style.objectFit = 'contain';
                document.getElementById('modal_image_preview_container').prepend(preview);
            }
            preview.src = e.target.result;
            preview.style.display = 'block';
        }; 
        reader.readAsDataURL(input.files[0]); 
    } 
}
function removeModalImage() { 
    document.getElementById('modal_product_image').value = '';
    let preview = document.getElementById('modal_image_preview_img');
    if (preview) preview.style.display = 'none';
    let placeholder = document.getElementById('modal_image_placeholder');
    if (placeholder) placeholder.style.display = 'block';
}
function modalCalcMarkup() { 
    let cost = parseFloat(document.getElementById('modal_cost_price').value) || 0, selling = parseFloat(document.getElementById('modal_selling_price').value) || 0; 
    let profit = selling - cost, markup = cost > 0 ? (profit / cost) * 100 : 0; 
    document.getElementById('modal_profit_badge').innerText = 'TZS ' + profit.toLocaleString(undefined, {minimumFractionDigits: 2}); 
    document.getElementById('modal_markup_badge').innerText = markup.toFixed(2) + '%'; 
}
function modalCalcMinPrice() {
    let selling = parseFloat(document.getElementById('modal_selling_price').value) || 0;
    let discount = parseFloat(document.getElementById('modal_discount_rate').value) || 0;
    let minPrice = selling - (selling * discount / 100);
    document.getElementById('modal_min_selling_price').value = minPrice.toFixed(2);
}
function updateModalUnit(val) { document.querySelectorAll('.modal-unit-label').forEach(el => el.innerText = val); }
function saveAddAnother() { $('#addProductForm').data('add-another', true).submit(); }

$(document).ready(function() {
    $('#addProductForm').on('submit', function(e) {
        e.preventDefault();
        let addAnother = $(this).data('add-another') || false;
        const btn = $(this).find('[type="submit"]');
        const originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
        
        const messageContainer = $('#add-product-message');
        messageContainer.html('');

        $.ajax({
            url: '<?= buildUrl("api/create_product.php") ?>',
            type: 'POST',
            data: new FormData(this),
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    logReportAction('Created Product', 'User successfully created product: ' + $('#addProductForm [name="product_name"]').val());
                    Swal.fire({ 
                        icon: 'success', 
                        title: 'Product Created', 
                        text: res.message || 'Product has been created successfully.',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK' 
                    }).then(() => { 
                        if (addAnother) { 
                            $('#addProductForm')[0].reset(); 
                            refreshSKU(); 
                            refreshBarcode(); 
                            $('#tab1-tab').tab('show'); 
                            $('#addProductForm').data('add-another', false);
                        } else {
                            window.location.href = window.location.pathname + '?sort_by=created_at&sort_order=DESC';
                        } 
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function(xhr) {
                console.error('AJAX Error:', xhr);
                Swal.fire({ icon: 'error', title: 'Server Error', text: 'Could not connect to server.' });
            },
            complete: function() {
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });
});

function showQuickAddUnit() {
    Swal.fire({
        title: 'Add New Measurement Unit',
        html: `
            <div class="mb-3 text-start">
                <label class="form-label fw-bold">Unit Name (e.g. Kilogram)</label>
                <input type="text" id="swal_unit_name" class="form-control" placeholder="Full name">
            </div>
            <div class="mb-3 text-start">
                <label class="form-label fw-bold">Unit Code (e.g. kg)</label>
                <input type="text" id="swal_unit_code" class="form-control" placeholder="Short code">
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save Unit',
        preConfirm: () => {
            const name = document.getElementById('swal_unit_name').value;
            const code = document.getElementById('swal_unit_code').value;
            if (!name || !code) {
                Swal.showValidationMessage('Please enter both name and code');
                return false;
            }
            return { name: name, code: code };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('api/save_unit.php', {
                unit_name: result.value.name,
                unit_code: result.value.code
            }, function(res) {
                if (res.success) {
                    logReportAction('Quick Added Unit', 'User quick-added measurement unit: ' + result.value.code);
                    $('#unit_list').append(`<option value="${res.unit.unit_code}">${res.unit.unit_name}</option>`);
                    $('#modal_unit_input').val(res.unit.unit_code).trigger('change');
                    Swal.fire({
                        icon: 'success',
                        title: 'Success',
                        text: res.message,
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK'
                    });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function updatePerPage(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', val);
    url.searchParams.set('page', 1); // Reset to page 1
    window.location.href = url.toString();
}

function handleSearch(val) {
    logReportAction('Searched Products', 'User searched products for: ' + val);
    const url = new URL(window.location.href);
    url.searchParams.set('search', val);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

function printTable() {
    logReportAction('Printed Products List', 'User generated a printed list of products');
    window.print();
}

function copyTable() {
    // Select the table
    const table = document.getElementById('productsTable');
    if (!table) return;

    // Create a range object
    const range = document.createRange();  
    range.selectNode(table);  

    // Select the range
    window.getSelection().removeAllRanges(); 
    window.getSelection().addRange(range); 

    // Copy command
    try {  
        document.execCommand('copy'); 
        logReportAction('Copied Products Table', 'User copied the products table to clipboard');
        Swal.fire({
            icon: 'success',
            title: 'Copied!',
            text: 'Table data copied to clipboard.',
            confirmButtonColor: '#28a745',
            confirmButtonText: 'OK'
        });
    } catch(err) {  
        console.error('Oops, unable to copy'); 
    }

    // Clear selection
    window.getSelection().removeAllRanges(); 
}

function exportProducts() {
    // Clone current URL params to keep filters
    const url = new URL(window.location.href);
    // Replace products.php with api/export_products.php or similar if exists,
    // Or simpler: alert functionality for now if backend not ready.
    // Assuming a generic export function:
    // For now, let's just create a CSV from the table client side as a fallback
    
    let csv = [];
    const rows = document.querySelectorAll("table tr");
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        
        for (let j = 0; j < cols.length; j++) 
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        
        csv.push(row.join(","));        
    }

    downloadCSV(csv.join("\n"), "products_export.csv");
    logReportAction('Exported Products list', 'User exported product records to CSV/Excel');
}


function toggleView(viewType) {
    const tableView = $('#tableView');
    const cardView = $('#cardView');
    
    if (viewType === 'table') {
        tableView.removeClass('d-none');
        cardView.addClass('d-none');
        $('#btn-table-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-card-view').removeClass('btn-primary text-white').addClass('btn-light');
    } else {
        tableView.addClass('d-none');
        cardView.removeClass('d-none');
        $('#btn-card-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-table-view').removeClass('btn-primary text-white').addClass('btn-light');
    }
    
    // Only persist explicit desktop choices — mobile auto-switch must not pollute desktop pref
    if (window.innerWidth >= 768) {
        localStorage.setItem('productsView', viewType);
    }
}

// Load view preference on page load
$(document).ready(function() {
    if (window.innerWidth < 768) {
        toggleView('card'); // mobile always card — don't save
    } else {
        const savedView = localStorage.getItem('productsView') || 'table';
        toggleView(savedView);
    }
});

function downloadCSV(csv, filename) {
    let csvFile;
    let downloadLink;

    csvFile = new Blob([csv], {type: "text/csv"});
    downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}
</script>
<style>
#addProductModal .nav-tabs .nav-link { color: #4a5568; padding: 1rem 1.5rem; border-bottom: 3px solid transparent; }
#addProductModal .nav-tabs .nav-link.active { background: transparent !important; color: var(--bs-primary) !important; border-bottom: 3px solid var(--bs-primary) !important; }
.border-dashed { border: 2px dashed #cbd5e0 !important; }
.rounded-4 { border-radius: 1rem !important; }

/* Custom Styles for Consistency */
.bg-success-soft {
    background-color: rgba(25, 135, 84, 0.1) !important;
}

.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}
.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
    font-family: inherit;
    font-weight: 600;
}
.table thead th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    color: #333;
    font-weight: 600;
}
@media print {
    /* Set page margins */
    @page { margin: 1cm; }
    
    body { background: white !important; padding: 0 !important; padding-top: 0 !important; }
    .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
    
    /* Hide all UI elements except the table and print header */
    .btn-group, .d-flex.align-items-center.gap-3, .card-header, 
    .pagination, footer, #filterCollapse,
    .mb-4, .input-group, .navbar, .sidebar, 
    .ms-auto, .text-end, .dropdown, th:last-child, td:last-child,
    .badge.bg-success-soft, .product-img-wrapper {
        display: none !important;
    }

    /* Force Table View on Print */
    #tableView { display: block !important; }
    #cardView { display: none !important; }

    .card { border: none !important; box-shadow: none !important; }
    .card-body { padding: 0 !important; }

    /* Table data wasn't starting on the first printed page — the shared
       responsive.css rule `.card { page-break-inside: avoid }` applies to
       every .card on every page (deliberately left alone globally; 95/119
       print pages depend on it). Here the table's wrapping card is taller
       than one printable page, so "never break inside it" pushed the whole
       card to the top of page 2, leaving page 1 mostly blank below the
       header/stats. Overridden for just this page's table card so it can
       flow across pages like the table it contains; individual rows still
       can't split (global `tr { page-break-inside: avoid }` is untouched). */
    #tableView .card {
        page-break-inside: auto !important;
        break-inside: auto !important;
    }
    
    /* Table Styling for Print */
    .table { 
        width: 100% !important; 
        border: 1px solid #000 !important; 
        border-collapse: collapse !important; 
        background-color: #fff !important;
    }
    .table thead th { 
        background-color: #fff !important; 
        color: #000 !important; 
        border: 1px solid #000 !important; 
        text-transform: uppercase !important;
        font-size: 10pt !important;
        padding: 8px !important;
    }
    .table td { 
        background-color: #fff !important;
        color: #000 !important;
        border: 1px solid #000 !important; 
        font-size: 9pt !important; 
        padding: 6px !important;
    }
    
    /* Remove all table backgrounds and colors */
    .table-striped > tbody > tr:nth-of-type(odd) > * {
        background-color: #fff !important;
        box-shadow: none !important;
    }
    
    /* Convert badges to plain text for print */
    .badge {
        background-color: transparent !important;
        color: #000 !important;
        border: none !important;
        padding: 0 !important;
        font-weight: normal !important;
    }
    
    .text-success, .text-warning, .text-danger, .text-info {
        color: #000 !important;
    }

    /* Remove the right-side Actions column completely */
    th:last-child, td:last-child {
        display: none !important;
        border-right: none !important;
    }

    /* Ensure no stray vertical lines at the end */
    .table { border-right: 1px solid #000 !important; }

    /* Ensure the print header is visible and centered */
    .d-print-block { display: block !important; }

    /* Show stats cards in print */
    #print-stats-cards {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        align-items: stretch !important;
        margin-bottom: 0.5rem !important;
        gap: 10px !important;
    }
    #print-stats-cards > div {
        display: flex !important;
        flex: 1 1 25% !important;
        max-width: 25% !important;
        width: 25% !important;
        margin-bottom: 0 !important;
    }
    .custom-stat-card {
        width: 100% !important;
        height: 100% !important;
        padding: 10px !important;
        display: flex !important;
        align-items: center !important;
        -webkit-print-color-adjust: exact;
        background-color: #d1e7dd !important;
        border: 1px solid #badbcc !important;
    }
    .custom-stat-card .card-body {
        padding: 0 !important;
        width: 100% !important;
    }
    .stat-icon-circle {
        width: 35px !important;
        height: 35px !important;
        min-width: 35px !important;
        background: rgba(15, 81, 50, 0.1) !important;
        border-radius: 8px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .stat-icon-circle i {
        font-size: 1.1rem !important;
        margin: 0 !important;
    }
    .custom-stat-card h4 {
        font-size: 1.1rem !important;
        margin-bottom: 2px !important;
    }
    .custom-stat-card p {
        font-size: 0.7rem !important;
        margin-bottom: 0 !important;
    }
}

/* Screen Styles */
.stat-icon-circle {
    width: 48px;
    height: 48px;
    background: rgba(15, 81, 50, 0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #0f5132;
    transition: all 0.3s ease;
    flex-shrink: 0;
}
.stat-icon-circle i {
    font-size: 1.5rem;
}
.custom-stat-card:hover .stat-icon-circle {
    background: #0f5132;
    color: #fff;
    transform: scale(1.1);
}

</style>
<?php
include("footer.php");
ob_end_flush();
?>