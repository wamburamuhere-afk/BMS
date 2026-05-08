<?php
// File: product_create.php
ob_start();
require_once 'header.php';

// Check user role for product creation permissions
requireCreatePermission('products');

// Get current user info
$user_id = $_SESSION['user_id'];

// Get data for dropdowns
try {
    $categories = $pdo->query("SELECT category_id, category_name, parent_id FROM categories WHERE status = 'active' AND type = 'product' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

try {
    $brands = $pdo->query("SELECT brand_id, brand_name FROM brands WHERE status = 'active' ORDER BY brand_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $brands = [];
}

try {
    $suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $suppliers = [];
}

try {
    $tax_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage FROM tax_rates WHERE status = 'active' ORDER BY rate_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $tax_rates = [];
}

try {
    $warehouses = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $warehouses = [];
}

// Get measurement units
try {
    $units = $pdo->query("SELECT * FROM measurement_units WHERE status = 'active' ORDER BY unit_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $units = [];
}

// Helper functions
function generate_sku() {
    $prefix = 'PROD';
    $timestamp = time();
    $random = rand(100, 999);
    return $prefix . $timestamp . $random;
}

function generate_barcode() {
    // Generate EAN-13 barcode (13 digits)
    $country_code = '00'; // Default country code
    $company_code = rand(10000, 99999);
    $product_code = rand(10000, 99999);
    $barcode = $country_code . $company_code . $product_code;
    
    // Calculate check digit
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int)$barcode[$i];
        $sum += ($i % 2 == 0) ? $digit : $digit * 3;
    }
    $check_digit = (10 - ($sum % 10)) % 10;
    
    return $barcode . $check_digit;
}

// Helper functions removed, now in helpers.php
function build_category_tree($categories, $parent_id = 0, $depth = 0) {
    $html = '';
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parent_id) {
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
            $html .= '<option value="' . $category['category_id'] . '">' 
                   . $indent . htmlspecialchars($category['category_name']) . '</option>';
            $html .= build_category_tree($categories, $category['category_id'], $depth + 1);
        }
    }
    return $html;
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center bg-primary p-3 rounded shadow-sm">
                <div>
                    <h2 class="fw-bold text-white mb-0"><i class="bi bi-box-seam text-white"></i> Create New Product</h2>
                    <p class="text-white mb-0 opacity-75">Fill in the details below to add a new item to your inventory</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('products') ?>" class="btn btn-light border px-4">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                    <button type="submit" form="productForm" class="btn btn-primary px-4 shadow-sm">
                        <i class="bi bi-check-circle"></i> Create Product
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Navigation Tabs -->
    <div class="card border-0 shadow-sm overflow-hidden mb-4">
        <div class="card-header bg-white p-0 border-bottom">
            <ul class="nav nav-pills custom-tabs nav-justified" id="productTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active py-3 rounded-0" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">
                        <i class="bi bi-info-circle me-2"></i> General Info
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link py-3 rounded-0" id="pricing-tab" data-bs-toggle="tab" data-bs-target="#pricing" type="button" role="tab">
                        <i class="bi bi-cash-stack me-2"></i> Pricing & Profit
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link py-3 rounded-0" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory" type="button" role="tab">
                        <i class="bi bi-boxes me-2"></i> Inventory & Stock
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link py-3 rounded-0" id="advanced-tab" data-bs-toggle="tab" data-bs-target="#advanced" type="button" role="tab">
                        <i class="bi bi-gear me-2"></i> Advanced Details
                    </button>
                </li>
            </ul>
        </div>
        
        <div class="card-body p-4 pt-5">
            <div id="form-message" class="mb-4"></div>
            
            <form id="productForm" enctype="multipart/form-data">
                <div class="tab-content" id="productTabContent">
                    
                    <!-- Tab 1: General Information -->
                    <div class="tab-pane fade show active" id="general" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label for="product_name" class="form-label fw-bold">Product Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-lg bg-light border-0 py-3" id="product_name" name="product_name" 
                                               placeholder="e.g. Samsung Galaxy S21" required>
                                    </div>
                                    
                                    <div class="col-md-6 mt-4">
                                        <label for="sku" class="form-label fw-bold text-muted small uppercase">SKU (Internal Code)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control bg-light border-0" id="sku" name="sku" 
                                                   value="<?= generate_sku() ?>">
                                            <button type="button" class="btn btn-outline-secondary border-0 bg-light" onclick="generateNewSKU()">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mt-4">
                                        <label for="barcode" class="form-label fw-bold text-muted small">Barcode (Universal Code)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control bg-light border-0" id="barcode" name="barcode" 
                                                   value="<?= generate_barcode() ?>">
                                            <button type="button" class="btn btn-outline-secondary border-0 bg-light" onclick="generateNewBarcode()">
                                                <i class="bi bi-upc"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-md-12 mt-4">
                                        <label for="category_id" class="form-label fw-bold">Category</label>
                                        <div class="input-group">
                                            <select class="form-select bg-light border-0 py-2" id="category_id" name="category_id">
                                                <option value="">Select Category</option>
                                                <?= build_category_tree($categories) ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary border-0 bg-light-primary" onclick="showQuickCategoryModal()">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-md-12 mt-4">
                                        <label for="description" class="form-label fw-bold">Detailed Description</label>
                                        <textarea class="form-control bg-light border-0" id="description" name="description" 
                                                  rows="4" placeholder="Mention key features, specifications or other details..."></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 border-start ps-xxl-5">
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Product Image</label>
                                    <div id="imagePreview" class="border rounded-4 p-3 mb-3 d-flex align-items-center justify-content-center bg-light shadow-inner" style="height: 250px;">
                                        <div class="text-center opacity-50">
                                            <i class="bi bi-image-fill display-3"></i>
                                            <p class="small mt-2">Drophere or Click to Upload</p>
                                        </div>
                                    </div>
                                    <input type="file" class="form-control visually-hidden" id="product_image" name="product_image" 
                                           accept="image/*" onchange="previewImage(event)">
                                    <button type="button" class="btn btn-light border w-100 rounded-pill py-2" onclick="document.getElementById('product_image').click()">
                                        <i class="bi bi-upload me-1"></i> Choose Image
                                    </button>
                                </div>
                                
                                <div class="mb-3 p-3 bg-light rounded-4">
                                    <label class="form-label fw-bold">Status</label>
                                    <div class="d-flex flex-column gap-2">
                                        <div class="form-check custom-radio">
                                            <input class="form-check-input" type="radio" name="status" id="status_active" value="active" checked>
                                            <label class="form-check-label" for="status_active">Active <span class="text-muted small"> (Visible in Sales)</span></label>
                                        </div>
                                        <div class="form-check custom-radio">
                                            <input class="form-check-input" type="radio" name="status" id="status_inactive" value="inactive">
                                            <label class="form-check-label" for="status_inactive">Inactive <span class="text-muted small"> (Draft)</span></label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Tab Footer -->
                        <div class="d-flex justify-content-end mt-5 pt-4 border-top">
                            <button type="button" class="btn btn-primary px-5 py-2 rounded-pill shadow-sm" onclick="$('#pricing-tab').tab('show')">
                                Next: Pricing <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Tab 2: Pricing & Profit -->
                    <div class="tab-pane fade" id="pricing" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-md-6 mb-4">
                                <div class="p-4 bg-light rounded-4 border-0 h-100">
                                    <h6 class="fw-bold mb-4 border-bottom pb-2"><i class="bi bi-currency-dollar me-2 text-success"></i> Sales Pricing</h6>
                                    
                                    <div class="mb-3">
                                        <label for="cost_price" class="form-label fw-bold">Cost Price / Purchase Price <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-lg">
                                            <span class="input-group-text bg-white border-0">TZS</span>
                                            <input type="number" class="form-control border-0" id="cost_price" name="cost_price" 
                                                   min="0" step="0.01" value="0.00" required onkeyup="calculateMarkup()">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="selling_price" class="form-label fw-bold">Standard Selling Price <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-lg border border-primary rounded-3 overflow-hidden shadow-sm">
                                            <span class="input-group-text bg-white border-0 text-primary fw-bold">TZS</span>
                                            <input type="number" class="form-control border-0 fw-bold" id="selling_price" name="selling_price" 
                                                   min="0" step="0.01" value="0.00" required onkeyup="calculateMarkup(); calculateMinSellingPrice();">
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="wholesale_price" class="form-label fw-bold small text-muted">Wholesale Price</label>
                                            <input type="number" class="form-control bg-white border-0" id="wholesale_price" name="wholesale_price" 
                                                   min="0" step="0.01" value="0.00">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="discount_rate" class="form-label fw-bold small text-muted">Max Discount %</label>
                                            <div class="input-group">
                                                <input type="number" class="form-control bg-white border-0" id="discount_rate" name="discount_rate" 
                                                       min="0" max="100" step="0.01" value="0.00" onkeyup="calculateMinSellingPrice()">
                                                <span class="input-group-text border-0 bg-white">%</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <div class="p-4 bg-soft-primary rounded-4 border-0 h-100 shadow-inner">
                                    <h6 class="fw-bold mb-4 border-bottom pb-2"><i class="bi bi-graph-up-arrow me-2 text-primary"></i> Profit Calculation</h6>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-4">
                                            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                                                <label class="form-label text-muted small fw-bold mb-1 uppercase">Markup Percentage</label>
                                                <div class="d-flex align-items-center justify-content-center">
                                                    <input type="text" class="form-control-plaintext text-center fw-bold fs-3 border-0" id="markup_percentage" value="0.00" readonly style="width: 100px;">
                                                    <span class="fw-bold fs-3">%</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <div class="card border-0 shadow-sm rounded-4 text-center p-3">
                                                <label class="form-label text-muted small fw-bold mb-1 uppercase">Estimated Profit</label>
                                                <div class="d-flex align-items-center justify-content-center">
                                                    <span class="fw-bold fs-5 me-1">TZS</span>
                                                    <input type="text" class="form-control-plaintext text-center fw-bold fs-3 border-0" id="profit_margin" value="0.00" readonly style="width: 130px;">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-muted">Tax Configuration</label>
                                        <select class="form-select border-0 bg-white py-2 shadow-sm" id="tax_id" name="tax_id">
                                            <option value="">No Tax (Default)</option>
                                            <?php foreach ($tax_rates as $tax): ?>
                                                <option value="<?= $tax['rate_id'] ?>">
                                                    <?= htmlspecialchars($tax['rate_name']) ?> (<?= $tax['rate_percentage'] ?>%)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="p-3 bg-white border rounded-3 mt-4">
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted small fw-bold">MINIMUM SELLING PRICE</span>
                                            <span class="badge bg-danger">Auto-Safe Limit</span>
                                        </div>
                                        <div class="d-flex align-items-baseline mt-2">
                                            <h4 class="text-dark fw-bold mb-0 me-2" id="min_selling_price_display">0.00</h4>
                                            <span class="text-muted small">TZS</span>
                                        </div>
                                        <input type="hidden" id="min_selling_price" name="min_selling_price" value="0.00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab Footer -->
                        <div class="d-flex justify-content-between mt-5 pt-4 border-top">
                            <button type="button" class="btn btn-light px-4 py-2 rounded-pill border" onclick="$('#general-tab').tab('show')">
                                <i class="bi bi-arrow-left me-2"></i> Previous
                            </button>
                            <button type="button" class="btn btn-primary px-5 py-2 rounded-pill shadow-sm" onclick="$('#inventory-tab').tab('show')">
                                Next: Inventory <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Tab 3: Inventory & Stock -->
                    <div class="tab-pane fade" id="inventory" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-md-7">
                                <div class="card border border-light rounded-4 h-100 shadow-sm">
                                    <div class="card-header bg-white border-0 pt-4 px-4">
                                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-shield-check me-2 text-primary"></i> Stock Control</h6>
                                    </div>
                                    <div class="card-body p-4">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="unit" class="form-label fw-bold">Unit of Measure <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control bg-light border-0 py-2" name="unit" id="unit" list="unit_list" 
                                                           placeholder="e.g. pcs, kg, Box" required value="pcs" onchange="updateUnitLabels()">
                                                    <datalist id="unit_list">
                                                        <?php foreach ($units as $u): ?>
                                                            <option value="<?= htmlspecialchars($u['unit_code']) ?>"><?= htmlspecialchars($u['unit_name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </datalist>
                                                    <button class="btn btn-outline-primary border-0 bg-light" type="button" onclick="showQuickAddUnit()" title="Add to Database">
                                                        <i class="bi bi-plus-lg"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Tracking</label>
                                                <div class="form-check form-switch p-3 bg-light rounded-3">
                                                    <input class="form-check-input" type="checkbox" id="track_inventory" name="track_inventory" checked>
                                                    <label class="form-check-label fw-bold ms-2" for="track_inventory">Track Stock Levels</label>
                                                </div>
                                            </div>

                                            <div class="col-md-4 mt-4">
                                                <label for="reorder_level" class="form-label fw-bold small text-muted">Reorder Alert Level</label>
                                                <input type="number" class="form-control bg-light border-0 py-2" id="reorder_level" name="reorder_level" 
                                                       min="0" step="0.001" value="0">
                                            </div>
                                            
                                            <div class="col-md-4 mt-4">
                                                <label for="min_stock_level" class="form-label fw-bold small text-muted">Safety Stock (Min)</label>
                                                <input type="number" class="form-control bg-light border-0 py-2" id="min_stock_level" name="min_stock_level" 
                                                       min="0" step="0.001" value="0">
                                            </div>
                                            
                                            <div class="col-md-4 mt-4">
                                                <label for="max_stock_level" class="form-label fw-bold small text-muted">Max Stock Level</label>
                                                <input type="number" class="form-control bg-light border-0 py-2" id="max_stock_level" name="max_stock_level" 
                                                       min="0" step="0.001" value="0">
                                            </div>
                                        </div>

                                        <div class="mt-5 pt-3 border-top">
                                            <h6 class="fw-bold mb-3"><i class="bi bi-geo-alt me-2 text-warning"></i> Opening Stock (Optional)</h6>
                                            <div class="row g-2" id="initialStockSection">
                                                <?php if (!empty($warehouses)): ?>
                                                    <?php foreach ($warehouses as $warehouse): ?>
                                                    <div class="col-md-6 mb-2">
                                                        <div class="p-3 bg-white border border-light rounded-3 shadow-sm d-flex justify-content-between align-items-center">
                                                            <label class="small fw-bold mb-0 text-muted"><?= htmlspecialchars($warehouse['warehouse_name']) ?></label>
                                                            <div class="input-group input-group-sm" style="max-width: 150px;">
                                                                <input type="number" class="form-control border-secondary" name="initial_stock[<?= $warehouse['warehouse_id'] ?>]" 
                                                                       min="0" step="0.001" value="0">
                                                                <span class="input-group-text bg-light unit-label">pcs</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <div class="p-4 bg-light rounded-4 h-100">
                                    <h6 class="fw-bold mb-4"><i class="bi bi-rulers me-2 text-secondary"></i> Physical Specifications</h6>
                                    
                                    <div class="mb-4">
                                        <label for="weight" class="form-label fw-bold small">Weight (Gross)</label>
                                        <div class="input-group py-1">
                                            <input type="number" class="form-control bg-white border-0 py-2 px-3" id="weight" name="weight" 
                                                   min="0" step="0.001" value="0.000">
                                            <span class="input-group-text bg-white border-0 fw-bold">kg</span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Dimensions (Length × Width × Height)</label>
                                        <div class="row g-2">
                                            <div class="col-4">
                                                <div class="input-group shadow-sm rounded-3 overflow-hidden">
                                                    <span class="input-group-text bg-white border-0 small text-muted">L</span>
                                                    <input type="number" class="form-control border-0 text-center py-2" id="dim_length" onchange="updateDimensions()" placeholder="0">
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="input-group shadow-sm rounded-3 overflow-hidden">
                                                    <span class="input-group-text bg-white border-0 small text-muted">W</span>
                                                    <input type="number" class="form-control border-0 text-center py-2" id="dim_width" onchange="updateDimensions()" placeholder="0">
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="input-group shadow-sm rounded-3 overflow-hidden">
                                                    <span class="input-group-text bg-white border-0 small text-muted">H</span>
                                                    <input type="number" class="form-control border-0 text-center py-2" id="dim_height" onchange="updateDimensions()" placeholder="0">
                                                </div>
                                            </div>
                                        </div>
                                        <input type="hidden" id="dimensions" name="dimensions">
                                        <div class="text-end mt-2">
                                            <span class="text-muted extra-small">Metric: Centimeters (cm)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab Footer -->
                        <div class="d-flex justify-content-between mt-5 pt-4 border-top">
                            <button type="button" class="btn btn-light px-4 py-2 rounded-pill border" onclick="$('#pricing-tab').tab('show')">
                                <i class="bi bi-arrow-left me-2"></i> Previous
                            </button>
                            <button type="button" class="btn btn-primary px-5 py-2 rounded-pill shadow-sm" onclick="$('#advanced-tab').tab('show')">
                                Next: Additional <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Tab 4: Advanced Details -->
                    <div class="tab-pane fade" id="advanced" role="tabpanel">
                        <div class="row g-4">
                            <div class="col-md-6 mb-4">
                                <div class="p-4 bg-light rounded-4 h-100 border border-light">
                                    <h6 class="fw-bold mb-4 border-bottom pb-2 text-dark"><i class="bi bi-truck me-2 text-info"></i> Supply Chain</h6>
                                    
                                    <div class="mb-4">
                                        <label for="brand_id" class="form-label fw-bold small">Brand</label>
                                        <div class="input-group">
                                            <select class="form-select border-0 py-2 shadow-sm" id="brand_id" name="brand_id">
                                                <option value="">Select Brand</option>
                                                <?php foreach ($brands as $brand): ?>
                                                    <option value="<?= $brand['brand_id'] ?>">
                                                        <?= htmlspecialchars($brand['brand_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-white border shadow-sm ms-2 rounded" onclick="showQuickBrandModal()">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label for="supplier_id" class="form-label fw-bold small">Preferred Supplier</label>
                                        <select class="form-select border-0 py-2 shadow-sm" id="supplier_id" name="supplier_id">
                                            <option value="">Select Supplier</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?= $supplier['supplier_id'] ?>">
                                                    <?= htmlspecialchars($supplier['supplier_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label for="manufacturer" class="form-label fw-bold small">Manufacturer (Optional)</label>
                                        <input type="text" class="form-control border-0 py-2 shadow-sm" id="manufacturer" name="manufacturer" 
                                               placeholder="Manufacturer name">
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <div class="p-4 bg-light rounded-4 h-100 border border-light">
                                    <h6 class="fw-bold mb-4 border-bottom pb-2 text-dark"><i class="bi bi-patch-check me-2 text-success"></i> Other Details</h6>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="model" class="form-label fw-bold small">Model / Series</label>
                                            <input type="text" class="form-control border-0 shadow-sm" id="model" name="model">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="serial_number" class="form-label fw-bold small">Serial Number</label>
                                            <input type="text" class="form-control border-0 shadow-sm" id="serial_number" name="serial_number">
                                        </div>
                                        <div class="col-md-6 mt-4">
                                            <label for="warranty_period" class="form-label fw-bold small">Warranty (Months)</label>
                                            <input type="number" class="form-control border-0 shadow-sm" id="warranty_period" name="warranty_period" min="0" value="0">
                                        </div>
                                        <div class="col-md-6 mt-4">
                                            <label for="expiry_days" class="form-label fw-bold small text-muted">Shelf Life (Days)</label>
                                            <input type="number" class="form-control border-0 shadow-sm" id="expiry_days" name="expiry_days" min="0" value="0">
                                        </div>
                                    </div>

                                    <div class="mt-4 pt-3">
                                        <div class="bg-white p-3 rounded-3 shadow-inner border">
                                            <div class="form-check custom-radio mb-2">
                                                <input class="form-check-input" type="checkbox" id="is_service" name="is_service">
                                                <label class="form-check-label fw-bold" for="is_service">This is a virtual service <span class="text-muted small fw-normal">(No physical stock)</span></label>
                                            </div>
                                            <div class="form-check custom-radio">
                                                <input class="form-check-input" type="checkbox" id="is_taxable" name="is_taxable" checked>
                                                <label class="form-check-label fw-bold" for="is_taxable">Enable Tax calculation for this item</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tab Footer -->
                        <div class="d-flex justify-content-between mt-5 pt-4 border-top">
                            <button type="button" class="btn btn-light px-4 py-2 rounded-pill border" onclick="$('#inventory-tab').tab('show')">
                                <i class="bi bi-arrow-left me-2"></i> Previous
                            </button>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-primary px-4 py-2 rounded-pill" onclick="saveAsDraft()">
                                    <i class="bi bi-save me-1"></i> Save Draft
                                </button>
                                <button type="submit" class="btn btn-success px-5 py-2 rounded-pill shadow-sm fw-bold">
                                    <i class="bi bi-check-circle-fill me-1"></i> Finalize & Create
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
                
                <input type="hidden" name="created_by" value="<?= $user_id ?>">
            </form>
        </div>
    </div>
</div>

<!-- Barcode Scanner Modal -->
<div class="modal fade" id="barcodeScannerModal" tabindex="-1" aria-labelledby="barcodeScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="barcodeScannerModalLabel">
                    <i class="bi bi-upc"></i> Scan Barcode
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <div id="scannerContainer" class="border rounded p-3" style="height: 300px;">
                        <div class="d-flex align-items-center justify-content-center h-100">
                            <div class="text-center">
                                <i class="bi bi-camera" style="font-size: 3rem; color: #6c757d;"></i>
                                <p class="mt-2 text-muted">Click to start camera</p>
                                <button type="button" class="btn btn-primary mt-2" onclick="startBarcodeScanner()">
                                    <i class="bi bi-camera-video"></i> Start Scanner
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="input-group">
                    <input type="text" class="form-control" id="manualBarcodeInput" placeholder="Or enter barcode manually">
                    <button class="btn btn-outline-secondary" type="button" onclick="useManualBarcode()">
                        <i class="bi bi-check"></i> Use
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Category Modal -->
<div class="modal fade" id="quickCategoryModal" tabindex="-1" aria-labelledby="quickCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="quickCategoryModalLabel">
                    <i class="bi bi-plus-circle"></i> Quick Add Category
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Category Name</label>
                    <input type="text" class="form-control" id="quickCategoryName" placeholder="Enter category name">
                </div>
                <div class="mb-3">
                    <label class="form-label">Parent Category</label>
                    <select class="form-select" id="quickCategoryParent">
                        <option value="0">None (Top Level)</option>
                        <?= build_category_tree($categories) ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveQuickCategory()">Add Category</button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Brand Modal -->
<div class="modal fade" id="quickBrandModal" tabindex="-1" aria-labelledby="quickBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="quickBrandModalLabel">
                    <i class="bi bi-plus-circle"></i> Quick Add Brand
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="quickBrandName" placeholder="Enter brand name">
                </div>
                <!-- Website is optional now, and we can skip it for 'Quick' add to keep it simple, or add it. Let's keep it simple or user might ask where it is. I'll add it to be safe since they just asked about it. -->
                <div class="mb-3">
                    <label class="form-label">Website (Optional)</label>
                    <input type="url" class="form-control" id="quickBrandWebsite" placeholder="https://example.com">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveQuickBrand()">Add Brand</button>
            </div>
        </div>
    </div>
</div>
<?php include 'product_create_footer.php'; ?>