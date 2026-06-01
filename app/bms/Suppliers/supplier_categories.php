<?php
// scope-audit: skip — supplier categories are global configuration, not project-scoped
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('categories');

// Include the header
includeHeader();

// Permission flags
$can_manage_categories = isAdmin() || canEdit('categories');


// Get categories
$stmt = $pdo->query("
    SELECT sc.*, u.username as created_by_name, 
           COUNT(s.supplier_id) as supplier_count
    FROM supplier_categories sc
    LEFT JOIN suppliers s ON sc.category_id = s.category_id
    LEFT JOIN users u ON sc.created_by = u.user_id
    GROUP BY sc.category_id
    ORDER BY sc.category_name ASC
");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions removed, now in helpers.php
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-tags"></i> Supplier Categories</h2>
                    <p class="text-muted mb-0">Manage supplier categories for better organization</p>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="bi bi-plus-circle"></i> Add Category
                </button>
            </div>
        </div>
    </div>

    <!-- Categories Table -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">All Categories</h5>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($categories) > 0): ?>
                <div class="table-responsive">
                    <table id="supplierCategoriesTable" class="table table-striped table-hover w-100">
                        <thead>
                            <tr>
                                <th>Category Name</th>
                                <th>Description</th>
                                <th>Suppliers</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($category['category_name']) ?></strong>
                                </td>
                                <td>
                                    <?= !empty($category['description']) ? htmlspecialchars(substr($category['description'], 0, 100)) . '...' : 'N/A' ?>
                                </td>
                                <td>
                                    <span class="badge bg-primary"><?= $category['supplier_count'] ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($category['status']) ?>">
                                        <?= ucfirst($category['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= htmlspecialchars($category['created_by_name']) ?>
                                    <br>
                                    <small class="text-muted"><?= date('M d, Y', strtotime($category['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="dropdown action-dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editCategory(<?= $category['category_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            </li>
                                            <?php if ($category['status'] == 'active'): ?>
                                            <li>
                                                <a class="dropdown-item text-warning" href="#" onclick="updateCategoryStatus(<?= $category['category_id'] ?>, 'inactive')">
                                                    <i class="bi bi-pause-circle"></i> Deactivate
                                                </a>
                                            </li>
                                            <?php else: ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" onclick="updateCategoryStatus(<?= $category['category_id'] ?>, 'active')">
                                                    <i class="bi bi-play-circle"></i> Activate
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($category['supplier_count'] == 0): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDeleteCategory(<?= $category['category_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete
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
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-tags" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Categories Found</h4>
                    <p class="text-muted">Get started by adding your first category.</p>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="bi bi-plus-circle"></i> Add Your First Category
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addCategoryModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Category
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addCategoryForm">
                <div class="modal-body">
                    <div id="add-category-message" class="mb-3"></div>
                    
                    <div class="mb-3">
                        <label for="category_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="category_name" name="category_name" required placeholder="Enter category name">
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Category description"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="editCategoryModalLabel">
                    <i class="bi bi-pencil"></i> Edit Category
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editCategoryForm">
                <div class="modal-body">
                    <div id="edit-category-message" class="mb-3"></div>
                    
                    <input type="hidden" id="edit_category_id" name="category_id">
                    
                    <div class="mb-3">
                        <label for="edit_category_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_category_name" name="category_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_status" class="form-label">Status</label>
                        <select class="form-select" id="edit_status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i> Update Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // §UI-2 — DataTable (search / sort / paginate). Actions column not sortable.
    if (!$.fn.DataTable.isDataTable('#supplierCategoriesTable')) {
        $('#supplierCategoriesTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: { emptyTable: 'No supplier categories found.', zeroRecords: 'No matching categories.' }
        });
    }

    // Add category form submission
    $('#addCategoryForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: 'api/add_supplier_category.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#add-category-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-category-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Category');
                }
            },
            error: function() {
                $('#add-category-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Category');
            }
        });
    });

    // Edit category form submission
    $('#editCategoryForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: 'api/update_supplier_category.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#edit-category-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#edit-category-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Category');
                }
            },
            error: function() {
                $('#edit-category-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Category');
            }
        });
    });

    // Reset form when modal is closed
    $('#addCategoryModal').on('hidden.bs.modal', function() {
        $('#addCategoryForm')[0].reset();
        $('#add-category-message').html('');
        $('#addCategoryForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Category');
    });
    
    $('#editCategoryModal').on('hidden.bs.modal', function() {
        $('#editCategoryForm')[0].reset();
        $('#edit-category-message').html('');
        $('#editCategoryForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Category');
    });
});

function editCategory(categoryId) {
    // Load category data
    $.ajax({
}

.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 150px;
}

.badge {
    font-size: 0.75em;
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
include("footer.php");
ob_end_flush();
?>