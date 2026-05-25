<?php
/**
 * File: app/bms/product/product_import.php
 * Product Bulk Import
 */
ob_start();
require_once 'header.php';

// Phase 5d — enforce view permission first (admin auto-bypass), then check create
autoEnforcePermission('products');

// Check user role for permissions
requireCreatePermission('products');
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-file-earmark-arrow-up"></i> Import Products</h2>
                    <p class="text-muted mb-0">Upload a CSV file to bulk import products</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('products') ?>" class="btn btn-primary">
                        <i class="bi bi-box"></i> Back to Products
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Import Form -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 text-dark"><i class="bi bi-upload"></i> Upload File</h5>
                </div>
                <div class="card-body">
                    <form id="importForm" enctype="multipart/form-data">
                        <div class="mb-4 text-center py-4 border-dashed rounded bg-light">
                            <i class="bi bi-file-earmark-excel text-success" style="font-size: 3rem;"></i>
                            <div class="mt-3">
                                <label for="importFile" class="form-label fw-bold">Select CSV File</label>
                                <input class="form-control mx-auto" type="file" id="importFile" name="file" accept=".csv" style="max-width: 400px;" required>
                                <p class="text-muted small mt-2">Only CSV files are supported (.csv)</p>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Options</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="update_existing" id="update_existing" value="1">
                                <label class="form-check-label" for="update_existing">
                                    Update existing products (matches by SKU)
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="skip_errors" id="skip_errors" value="1" checked>
                                <label class="form-check-label" for="skip_errors">
                                    Skip rows with validation errors
                                </label>
                            </div>
                        </div>

                        <div class="d-grid mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-circle"></i> Start Import Process
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Field Guide -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 text-dark"><i class="bi bi-book"></i> Field Mapping Guide</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered small">
                            <thead class="bg-light">
                                <tr>
                                    <th>Field Name</th>
                                    <th>Required</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>sku</code></td>
                                    <td>Yes</td>
                                    <td>Unique identifier for the product</td>
                                </tr>
                                <tr>
                                    <td><code>product_name</code></td>
                                    <td>Yes</td>
                                    <td>The name of the product</td>
                                </tr>
                                <tr>
                                    <td><code>category_name</code></td>
                                    <td>No</td>
                                    <td>Must match an existing category name</td>
                                </tr>
                                <tr>
                                    <td><code>cost_price</code></td>
                                    <td>Yes</td>
                                    <td>Purchase cost (numbers only)</td>
                                </tr>
                                <tr>
                                    <td><code>selling_price</code></td>
                                    <td>Yes</td>
                                    <td>Sale price (numbers only)</td>
                                </tr>
                                <tr>
                                    <td><code>stock_quantity</code></td>
                                    <td>No</td>
                                    <td>Initial stock level (defaults to 0)</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar / Instructions -->
        <div class="col-md-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-light border-bottom text-dark">
                    <h5 class="mb-0"><i class="bi bi-question-circle"></i> Instructions</h5>
                </div>
                <div class="card-body">
                    <h6>Step 1: Download Template</h6>
                    <p class="text-muted small">Start by downloading our CSV template to ensure your data is formatted correctly.</p>
                    <button type="button" class="btn btn-outline-primary btn-sm mb-4" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download CSV Template
                    </button>

                    <h6>Step 2: Prepare Your Data</h6>
                    <p class="text-muted small">Fill in the template with your product details. Ensure SKU is unique for each product.</p>

                    <h6>Step 3: Upload and Process</h6>
                    <p class="text-muted small">Upload your completed CSV file and click "Start Import". We'll validate your data before importing.</p>
                    
                    <div class="alert alert-warning small py-2">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Large files may take several minutes to process.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function downloadTemplate() {
    logReportAction('Downloaded Product Import Template', 'User downloaded the CSV template for bulk product import');
    const csv = "sku,product_name,category_name,cost_price,selling_price,stock_quantity,unit,barcode\nPROD001,Example Product,Electronics,50000,75000,10,pcs,123456789";
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', 'product_import_template.csv');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

$('#importForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    Swal.fire({
        title: 'Processing File',
        text: 'Please wait while we validate and import your data...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    $.ajax({
        url: '<?= getUrl('/api/import_products.php') ?>',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            Swal.close();
            if (res.success) {
                logReportAction('Imported Products Bulk', 'User successfully imported ' + res.imported + ' products and updated ' + res.updated);
                Swal.fire({
                    title: 'Import Successful!',
                    text: `${res.imported} products imported, ${res.updated} updated.`,
                    icon: 'success'
                }).then(() => location.href = '<?= getUrl('products') ?>');
            } else {
                Swal.fire('Import Failed', res.message, 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'An error occurred during transport.', 'error');
        }
    });
});
$(document).ready(function() {
    logReportAction('Viewed Product Import Page', 'User viewed the bulk product import page');
});
</script>

<style>
.border-dashed {
    border: 2px dashed #dee2e6 !important;
}
.bg-light {
    background-color: #f8f9fa !important;
}
.card {
     background-color: white !important;
     border: 1px solid #dee2e6 !important;
}

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.table thead th {
    background-color: #f8f9fa !important;
}
</style>
</style>

<?php 
require_once 'footer.php';
ob_end_flush();
?>
