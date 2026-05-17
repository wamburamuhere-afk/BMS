<?php
// File: product_edit.php
ob_start();
require_once 'header.php';

// Check if product ID is provided
if (!isset($_GET['id'])) {
    header("Location: products.php?error=Invalid Product ID");
    exit();
}

$product_id = intval($_GET['id']);

// Check user role for product editing permissions
requireEditPermission('products');

// Get current user info
$user_id = $_SESSION['user_id'];

// Get product details
try {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
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

// Fetch current stock per warehouse for this product
$stock_per_warehouse = [];
try {
    $stmt = $pdo->prepare("SELECT warehouse_id, stock_quantity FROM product_stocks WHERE product_id = ?");
    $stmt->execute([$product_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stock_per_warehouse[$row['warehouse_id']] = $row['stock_quantity'];
    }
} catch (PDOException $e) {
    $stock_per_warehouse = [];
}

// Get measurement units
try {
    $units = $pdo->query("SELECT * FROM measurement_units WHERE status = 'active' ORDER BY unit_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $units = [];
}

// Helper function
function build_category_tree($categories, $parent_id = 0, $depth = 0, $selected_id = 0) {
    $html = '';
    foreach ($categories as $category) {
        if ($category['parent_id'] == $parent_id) {
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $depth);
            $selected = ($category['category_id'] == $selected_id) ? 'selected' : '';
            $html .= '<option value="' . $category['category_id'] . '" ' . $selected . '>' 
                   . $indent . htmlspecialchars($category['category_name']) . '</option>';
            $html .= build_category_tree($categories, $category['category_id'], $depth + 1, $selected_id);
        }
    }
    return $html;
}

// Parse dimensions
$dimensions = explode('×', str_replace(' cm', '', $product['dimensions'] ?? ''));
$dim_length = $dimensions[0] ?? 0;
$dim_width = $dimensions[1] ?? 0;
$dim_height = $dimensions[2] ?? 0;
?>

<script>
const IS_EDIT = true;
const PRODUCT_ID = <?= $product_id ?>;

$(document).ready(function() {
    $('.select2-static').each(function() {
        $(this).select2({ theme: 'bootstrap-5', placeholder: 'Select...', allowClear: true, width: '100%' });
    });
});

</script>

<style>
/* Mobile Responsive Adjustments */
@media (max-width: 768px) {
    .container-fluid {
        padding-left: 10px !important;
        padding-right: 10px !important;
        overflow-x: hidden !important;
    }
    
    h2 { font-size: 1.25rem !important; }
    
    .card-body {
        padding: 1rem !important;
    }
    
    .nav-pills.custom-tabs {
        display: flex !important;
        flex-wrap: wrap !important;
    }
    
    .nav-pills.custom-tabs .nav-item {
        flex: 1 0 0 !important;
        max-width: 25% !important;
    }
    
    .nav-pills.custom-tabs .nav-link {
        padding: 0.65rem 0.2rem !important;
        font-size: 0.65rem !important;
        word-break: break-word !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        min-height: 55px !important;
        border-bottom: 2px solid #eee !important;
        line-height: 1.1 !important;
    }
    
    .nav-pills.custom-tabs .nav-link i {
        font-size: 1rem !important;
        margin-right: 0 !important;
        margin-bottom: 4px !important;
    }
    
    .nav-pills.custom-tabs .nav-link.active {
        border-bottom: 2px solid var(--primary) !important;
    }
}
</style>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center bg-primary p-3 rounded shadow-sm">
                <div>
                    <h2 class="fw-bold text-white mb-0"><i class="bi bi-pencil-square text-white"></i> Edit <?= $product['is_service'] == 1 ? 'Service' : 'Product' ?></h2>
                    <p class="text-white mb-0 opacity-75">Update <?= $product['is_service'] == 1 ? 'service' : 'product' ?> information and details</p>
                </div>
                <!-- Desktop Actions -->
                <div class="d-none d-md-flex gap-2">
                    <a href="<?= $product['is_service'] == 1 ? getUrl('services') : getUrl('products') ?>" class="btn btn-light border px-4">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                    <button type="submit" form="productForm" class="btn btn-primary px-4 shadow-sm">
                        <i class="bi bi-check-circle"></i> Update <?= $product['is_service'] == 1 ? 'Service' : 'Product' ?>
                    </button>
                </div>

                <!-- Mobile Actions (Dropdown) -->
                <div class="d-flex d-md-none ms-auto">
                    <div class="dropdown">
                        <button class="btn btn-light btn-sm dropdown-toggle shadow-sm px-3 fw-bold" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i> Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="z-index: 1060;">
                            <li>
                                <a class="dropdown-item py-2" href="<?= $product['is_service'] == 1 ? getUrl('services') : getUrl('products') ?>">
                                    <i class="bi bi-arrow-left text-secondary me-2"></i> Back to <?= $product['is_service'] == 1 ? 'Services' : 'List' ?>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button type="button" class="dropdown-item py-2 text-primary fw-bold" onclick="$('#productForm').submit()">
                                    <i class="bi bi-check-circle me-2"></i> Update <?= $product['is_service'] == 1 ? 'Service' : 'Product' ?>
                                </button>
                            </li>
                        </ul>
                    </div>
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

                        <input type="hidden" name="is_service" value="<?= $product['is_service'] ? '1' : '0' ?>">
                        <input type="hidden" name="track_inventory" value="<?= $product['track_inventory'] ? '1' : '0' ?>">

                        <div class="row g-4">
                            <div class="col-md-8">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label for="product_name" class="form-label fw-bold">Product Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-lg bg-light border-0 py-3" id="product_name" name="product_name" 
                                               placeholder="e.g. Samsung Galaxy S21" value="<?= safe_output($product['product_name']) ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mt-4">
                                        <label for="sku" class="form-label fw-bold text-muted small uppercase">SKU (Internal Code)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control bg-light border-0" id="sku" name="sku" 
                                                   value="<?= safe_output($product['sku']) ?>">
                                            <button type="button" class="btn btn-outline-secondary border-0 bg-light" onclick="generateNewSKU()">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 mt-4">
                                        <label for="barcode" class="form-label fw-bold text-muted small">Barcode (Universal Code)</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control bg-light border-0" id="barcode" name="barcode" 
                                                   value="<?= safe_output($product['barcode']) ?>">
                                            <button type="button" class="btn btn-outline-secondary border-0 bg-light" onclick="generateNewBarcode()">
                                                <i class="bi bi-upc"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-md-12 mt-4">
                                        <label for="category_id" class="form-label fw-bold">Category</label>
                                        <div class="input-group">
                                            <select class="form-select bg-light border-0 py-2 select2-static" id="category_id" name="category_id">
                                                <option value="">Select Category</option>
                                                <?= build_category_tree($categories, 0, 0, $product['category_id']) ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary border-0 bg-light-primary" onclick="showQuickCategoryModal()">
                                                <i class="bi bi-plus-lg"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="col-md-12 mt-4">
                                        <label for="description" class="form-label fw-bold">Detailed Description</label>
                                        <textarea class="form-control bg-light border-0" id="description" name="description" 
                                                  rows="4" placeholder="Mention key features, specifications or other details..."><?= safe_output($product['description']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 border-start ps-xxl-5">
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Product Image</label>
                                    <div id="imagePreview" class="border rounded-4 p-3 mb-3 d-flex align-items-center justify-content-center <?= !empty($product['image_url']) ? 'bg-white' : 'bg-light shadow-inner' ?>" style="height: 250px;">
                                        <?php if (!empty($product['image_url'])): ?>
                                        <div class="position-relative w-100 h-100 d-flex align-items-center justify-content-center">
                                            <img src="<?= safe_output($product['image_url']) ?>" class="img-fluid rounded shadow-sm" style="max-height: 100%; object-fit: contain;">
                                            <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 m-2 rounded-circle" onclick="removeImage(event)">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-center opacity-50">
                                            <i class="bi bi-image-fill display-3"></i>
                                            <p class="small mt-2">Drop here or Click to Upload</p>
                                        </div>
                                        <?php endif; ?>
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
                                            <input class="form-check-input" type="radio" name="status" id="status_active" value="active" <?= $product['status'] == 'active' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="status_active">Active <span class="text-muted small">(Visible in Sales)</span></label>
                                        </div>
                                        <div class="form-check custom-radio">
                                            <input class="form-check-input" type="radio" name="status" id="status_inactive" value="inactive" <?= $product['status'] == 'inactive' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="status_inactive">Inactive <span class="text-muted small">(Draft)</span></label>
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
                                                   min="0" step="0.01" value="<?= $product['cost_price'] ?>" required onkeyup="calculateMarkup()">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="selling_price" class="form-label fw-bold">Standard Selling Price <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-lg">
                                            <span class="input-group-text bg-success text-white fw-bold">TZS</span>
                                            <input type="number" class="form-control border-0 fw-bold" id="selling_price" name="selling_price"
                                                   min="0" step="0.01" value="<?= $product['selling_price'] ?>" required onkeyup="calculateMarkup(); calculateMinSellingPrice();">
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="wholesale_price" class="form-label fw-bold small text-muted">Wholesale Price</label>
                                            <div class="input-group">
                                                <span class="input-group-text bg-info text-white">TZS</span>
                                                <input type="number" class="form-control border-0" id="wholesale_price" name="wholesale_price"
                                                       min="0" step="0.01" value="<?= $product['wholesale_price'] ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="discount_rate" class="form-label fw-bold small text-muted">Max Discount %</label>
                                            <div class="input-group">
                                                <input type="number" class="form-control bg-white border-0" id="discount_rate" name="discount_rate" 
                                                       min="0" max="100" step="0.01" value="<?= $product['discount_rate'] ?>" onkeyup="calculateMinSellingPrice()">
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
                                        <select class="form-select border-0 bg-white py-2 shadow-sm select2-static" id="tax_id" name="tax_id">
                                            <option value="">No Tax (Default)</option>
                                            <?php foreach ($tax_rates as $tax): ?>
                                                <option value="<?= $tax['rate_id'] ?>" <?= $product['tax_id'] == $tax['rate_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($tax['rate_name']) ?> (<?= $tax['rate_percentage'] ?>%)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-check mt-2">
                                            <input class="form-check-input" type="checkbox" name="is_taxable" value="1" id="edit_is_taxable" <?= $product['is_taxable'] ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="edit_is_taxable">Calculate tax for this item</label>
                                        </div>
                                    </div>

                                    <div class="mb-3 mt-4">
                                        <label class="form-label fw-bold small text-muted">Min Selling Price</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-danger text-white">TZS</span>
                                            <input type="number" class="form-control" id="min_selling_price" name="min_selling_price"
                                                   value="<?= $product['min_selling_price'] ?>" step="0.01">
                                        </div>
                                        <small class="text-muted">Auto-calculated but can be overridden</small>
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
                                                           placeholder="e.g. pcs, kg, Box" required value="<?= safe_output($product['unit']) ?>" onchange="updateUnitLabels()">
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
                                        </div><!-- end row -->
                                        <div class="row g-3 mt-1" id="inventoryOnlySection">
                                            <div class="col-md-4 mt-4">
                                                <label for="reorder_level" class="form-label fw-bold small text-muted">Reorder Alert Level</label>
                                                <input type="number" class="form-control bg-light border-0 py-2" id="reorder_level" name="reorder_level" 
                                                       min="0" step="0.001" value="<?= $product['reorder_level'] ?>">
                                            </div>
                                            
                                            <div class="col-md-4 mt-4">
                                                <label for="min_stock_level" class="form-label fw-bold small text-muted">Safety Stock (Min)</label>
                                                <input type="number" class="form-control bg-light border-0 py-2" id="min_stock_level" name="min_stock_level" 
                                                       min="0" step="0.001" value="<?= $product['min_stock_level'] ?>">
                                            </div>
                                            
                                            <div class="col-md-4 mt-4">
                                                <label for="max_stock_level" class="form-label fw-bold small text-muted">Max Stock Level</label>
                                                <input type="number" class="form-control bg-light border-0 py-2" id="max_stock_level" name="max_stock_level" 
                                                       min="0" step="0.001" value="<?= $product['max_stock_level'] ?>">
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
                                                   min="0" step="0.001" value="<?= $product['weight'] ?>">
                                            <span class="input-group-text bg-white border-0 fw-bold">kg</span>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Dimensions (Length × Width × Height)</label>
                                        <div class="row g-2">
                                            <div class="col-4">
                                                <div class="input-group shadow-sm rounded-3 overflow-hidden">
                                                    <span class="input-group-text bg-white border-0 small text-muted">L</span>
                                                    <input type="number" class="form-control border-0 text-center py-2" id="dim_length" name="dim_length" onchange="updateDimensions()" value="<?= $dim_length ?>" placeholder="0">
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="input-group shadow-sm rounded-3 overflow-hidden">
                                                    <span class="input-group-text bg-white border-0 small text-muted">W</span>
                                                    <input type="number" class="form-control border-0 text-center py-2" id="dim_width" name="dim_width" onchange="updateDimensions()" value="<?= $dim_width ?>" placeholder="0">
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="input-group shadow-sm rounded-3 overflow-hidden">
                                                    <span class="input-group-text bg-white border-0 small text-muted">H</span>
                                                    <input type="number" class="form-control border-0 text-center py-2" id="dim_height" name="dim_height" onchange="updateDimensions()" value="<?= $dim_height ?>" placeholder="0">
                                                </div>
                                            </div>
                                        </div>
                                        <input type="hidden" id="dimensions" name="dimensions" value="<?= safe_output($product['dimensions']) ?>">
                                        <div class="text-end mt-2">
                                            <span class="text-muted extra-small">Metric: Centimeters (cm)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!$product['is_service'] && !empty($warehouses)): ?>
                        <div class="col-md-12 mt-4 p-3 bg-white border rounded">
                            <h6 class="fw-bold border-bottom pb-2 mb-3 text-primary">
                                <i class="bi bi-box-seam me-2"></i> CURRENT STOCK (Per Warehouse)
                            </h6>
                            <p class="text-muted small mb-3">Edit stock quantities per warehouse below. Changes are recorded as stock adjustments automatically.</p>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover border">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Store / Warehouse Name</th>
                                            <th style="width:200px;" class="text-center">Available Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($warehouses as $wh): ?>
                                        <tr>
                                            <td class="align-middle fw-semibold"><?= safe_output($wh['warehouse_name']) ?></td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control text-center"
                                                           name="stock[<?= $wh['warehouse_id'] ?>]"
                                                           value="<?= $stock_per_warehouse[$wh['warehouse_id']] ?? 0 ?>"
                                                           placeholder="0" min="0">
                                                    <span class="input-group-text unit-label"><?= htmlspecialchars($product['unit']) ?></span>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

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
                                            <select class="form-select border-0 py-2 shadow-sm select2-static" id="brand_id" name="brand_id">
                                                <option value="">Select Brand</option>
                                                <?php foreach ($brands as $brand): ?>
                                                    <option value="<?= $brand['brand_id'] ?>" <?= $product['brand_id'] == $brand['brand_id'] ? 'selected' : '' ?>>
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
                                        <select class="form-select border-0 py-2 shadow-sm select2-static" id="supplier_id" name="supplier_id">
                                            <option value="">Select Supplier</option>
                                            <?php foreach ($suppliers as $supplier): ?>
                                                <option value="<?= $supplier['supplier_id'] ?>" <?= $product['supplier_id'] == $supplier['supplier_id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($supplier['supplier_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label for="manufacturer" class="form-label fw-bold small">Manufacturer (Optional)</label>
                                        <input type="text" class="form-control border-0 py-2 shadow-sm" id="manufacturer" name="manufacturer" 
                                               placeholder="Manufacturer name" value="<?= safe_output($product['manufacturer']) ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <div class="p-4 bg-light rounded-4 h-100 border border-light">
                                    <h6 class="fw-bold mb-4 border-bottom pb-2 text-dark"><i class="bi bi-patch-check me-2 text-success"></i> Other Details</h6>
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="model" class="form-label fw-bold small">Model / Series</label>
                                            <input type="text" class="form-control border-0 shadow-sm" id="model" name="model" value="<?= safe_output($product['model']) ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="serial_number" class="form-label fw-bold small">Serial Number</label>
                                            <input type="text" class="form-control border-0 shadow-sm" id="serial_number" name="serial_number" value="<?= safe_output($product['serial_number']) ?>">
                                        </div>
                                        <div class="col-md-6 mt-4">
                                            <label for="warranty_period" class="form-label fw-bold small">Warranty (Months)</label>
                                            <input type="number" class="form-control border-0 shadow-sm" id="warranty_period" name="warranty_period" min="0" value="<?= $product['warranty_period'] ?>">
                                        </div>
                                        <div class="col-md-6 mt-4">
                                            <label for="expiry_days" class="form-label fw-bold small text-muted">Shelf Life (Days)</label>
                                            <input type="number" class="form-control border-0 shadow-sm" id="expiry_days" name="expiry_days" min="0" value="<?= $product['expiry_days'] ?>">
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
                                <button type="submit" class="btn btn-success px-5 py-2 rounded-pill shadow-sm fw-bold">
                                    <i class="bi bi-check-circle-fill me-1"></i> Update <?= $product['is_service'] == 1 ? 'Service' : 'Product' ?>
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
                
                <input type="hidden" name="updated_by" value="<?= $user_id ?>">
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
                        <?= build_category_tree($categories, 0, 0, 0) ?>
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
