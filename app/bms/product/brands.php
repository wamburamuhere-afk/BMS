<?php
/**
 * File: app/bms/product/brands.php
 * Product Brands Management
 */
ob_start();
require_once 'header.php';

// Check user role for permissions
requireViewPermission('products');
$can_manage_brands = canEdit('products');

// Get brands
try {
    $stmt = $pdo->query("SELECT * FROM brands ORDER BY brand_name ASC");
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $brands = [];
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-shield-check"></i> Product Brands</h2>
                    <p class="text-muted mb-0">Manage product brands and manufacturers</p>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" onclick="openAddModal()">
                        <i class="bi bi-plus-circle"></i> New Brand
                    </button>
                    <a href="<?= getUrl('products') ?>" class="btn btn-primary">
                        <i class="bi bi-box"></i> Back to Products
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Brands List -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 text-dark"><i class="bi bi-list-ul"></i> Brand List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>Brand Name</th>
                                    <th>Website/Link</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($brands)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">No brands found.</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($brands as $brand): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($brand['brand_name']) ?></strong></td>
                                        <td><small class="text-muted"><?= htmlspecialchars($brand['website'] ?? 'N/A') ?></small></td>
                                        <td>
                                            <span class="badge bg-<?= $brand['status'] == 'active' ? 'success' : 'danger' ?>">
                                                <?= ucfirst($brand['status'] ?? 'active') ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow">
                                                    <li>
                                                        <a class="dropdown-item" href="javascript:void(0)" onclick="editBrand(<?= htmlspecialchars(json_encode($brand)) ?>)">
                                                            <i class="bi bi-pencil text-info me-2"></i> Edit Brand
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteBrand(<?= $brand['brand_id'] ?>)">
                                                            <i class="bi bi-trash me-2"></i> Delete Brand
                                                        </a>
                                                    </li>
                                                </ul>
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

        <!-- Info Card -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light border-bottom">
                    <h5 class="mb-0 text-dark"><i class="bi bi-info-circle"></i> About Brands</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Brands help categorize products by their manufacturer or trademark. This makes it easier for customers to find specific products and for you to track performance by brand.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Brand Modal -->
<div class="modal fade" id="brandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">New Brand</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="brandForm">
                <div class="modal-body">
                    <input type="hidden" id="brand_id" name="brand_id">
                    <div class="mb-3">
                        <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="brand_name" id="modal_brand_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Website (Optional)</label>
                        <input type="url" class="form-control" name="website" id="modal_website" placeholder="https://example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="modal_description" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="modal_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    logReportAction('Viewed Brands Page', 'User viewed the product brands management page');
});

function openAddModal() {
    logReportAction('Viewed Create Brand Modal', 'User opened the modal to create a new brand');
    $('#brandForm')[0].reset();
    $('#brand_id').val('');
    $('#modalTitle').text('New Brand');
    $('#brandModal').modal('show');
}

function editBrand(brand) {
    logReportAction('Viewed Edit Brand Modal', 'User opened the modal to edit brand: ' + brand.brand_name);
    $('#modalTitle').text('Edit Brand');
    $('#brand_id').val(brand.brand_id);
    $('#modal_brand_name').val(brand.brand_name);
    $('#modal_website').val(brand.website || '');
    $('#modal_description').val(brand.description || '');
    $('#modal_status').val(brand.status || 'active');
    $('#brandModal').modal('show');
}

$('#brandForm').on('submit', function(e) {
    e.preventDefault();
    const formData = $(this).serialize();
    
    $.ajax({
        url: '<?= getUrl('/api/save_brand.php') ?>',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                const brandId = $('#brand_id').val();
                const action = brandId ? 'Updated Product Brand' : 'Created Product Brand';
                const description = brandId ? 'User updated brand: ' + $('#modal_brand_name').val() : 'User created a new brand: ' + $('#modal_brand_name').val();
                logReportAction(action, description);
                Swal.fire('Success', 'Brand saved successfully', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', res.message || 'Failed to save', 'error');
            }
        }
    });
});

function deleteBrand(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "Delete this brand? Products associated with it might need updates.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('/api/delete_brand.php') ?>',
                type: 'POST',
                data: { brand_id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        logReportAction('Deleted Product Brand', 'User deleted brand ID: ' + id);
                        Swal.fire('Deleted!', 'Brand has been deleted.', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}
</script>

<style>
.card {
    background-color: white !important;
    border: 1px solid #dee2e6 !important;
}
.table thead th {
    background-color: #f8f9fa !important;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
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
