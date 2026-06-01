<?php
/**
 * File: app/bms/product/categories.php
 * Product Categories Management
 */
ob_start();
require_once 'header.php';

// Check user role for permissions
requireViewPermission('products');
$can_manage_categories = canEdit('products');

// Get categories tree
try {
    $stmt = $pdo->query("SELECT * FROM categories WHERE type = 'product' ORDER BY category_name ASC");
    $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_categories = [];
    $error_message = $e->getMessage();
}

// Function to build tree 
function get_category_tree($categories, $parent_id = 0, $depth = 0) {
    $tree = [];
    foreach ($categories as $cat) {
        if ($cat['parent_id'] == $parent_id) {
            $cat['depth'] = $depth;
            $tree[] = $cat;
            $tree = array_merge($tree, get_category_tree($categories, $cat['category_id'], $depth + 1));
        }
    }
    return $tree;
}

$category_tree = get_category_tree($all_categories);
?>

<div class="product-categories-dashboard p-4 p-md-5" style="background: #ffffff; min-height: 100vh;">
    <!-- Print Only Header -->
    <div class="d-none d-print-block">
        <div style="text-align:center; padding: 20px 0; border-bottom: 3px solid #0d6efd; margin-bottom: 20px;">
            <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= htmlspecialchars($GLOBALS['DISPLAY_COMPANY_NAME'] ?? 'BMS') ?></h1>
            <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Product Categories Report</h2>
            <p style="color: #000; margin: 0; font-size: 10pt;">Report Date: <?= date('d M Y, H:i') ?></p>
        </div>
    </div>

    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('products') ?>">Inventory</a></li>
            <li class="breadcrumb-item active">Product Categories</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="row mb-5 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-1">
                        <i class="bi bi-tags text-primary me-2"></i>Product Categories
                    </h2>
                    <p class="text-muted mb-0">Manage product categories and hierarchy</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('products') ?>" class="btn btn-outline-secondary btn-sm px-3 shadow-sm d-flex align-items-center">
                        <i class="bi bi-arrow-left me-1"></i> Back to Inventory
                    </a>
                    <button type="button" class="btn btn-primary btn-sm shadow-sm px-4 fw-bold" onclick="openAddModal()">
                        <i class="bi bi-plus-circle me-1"></i> New Category
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Categories List -->
        <div class="col-md-9">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2"></i>Category List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="categoriesTable" class="table table-hover align-middle mb-0 w-100">
                            <thead class="bg-light text-uppercase small fw-bold">
                                <tr>
                                    <th style="width:50px;" class="ps-4">S/NO</th>
                                    <th>Category Name</th>
                                    <th>Description</th>
                                    <th>Status</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sn = 1; foreach ($category_tree as $cat): ?>
                                    <tr>
                                        <td class="ps-4 text-muted small fw-bold"><?= $sn++ ?></td>
                                        <td class="ps-4">
                                            <?= str_repeat('<span class="ms-3"></span>', $cat['depth']) ?>
                                            <?php if ($cat['depth'] > 0): ?><i class="bi bi-arrow-return-right text-muted me-1"></i><?php endif; ?>
                                            <strong><?= htmlspecialchars($cat['category_name']) ?></strong>
                                        </td>
                                        <td><small class="text-muted"><?= htmlspecialchars($cat['description'] ?? 'N/A') ?></small></td>
                                        <td>
                                            <span class="badge rounded-pill bg-<?= ($cat['status'] ?? 'active') == 'active' ? 'success' : 'danger' ?> bg-opacity-10 py-2 px-3" style="min-width: 80px; color: currentcolor !important;">
                                                <?= strtoupper($cat['status'] ?? 'ACTIVE') ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow">
                                                    <li>
                                                        <a class="dropdown-item btn-edit-cat" href="javascript:void(0)" 
                                                           data-cat='<?= htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8') ?>'>
                                                            <i class="bi bi-pencil text-info me-2"></i> Edit Category
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger btn-delete-cat" href="javascript:void(0)" 
                                                           data-id="<?= $cat['category_id'] ?>">
                                                            <i class="bi bi-trash me-2"></i> Delete Category
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Help/Summary -->
        <div class="col-md-3 d-print-none">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-lightbulb text-warning me-2"></i>Quick Tips</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex mb-3">
                        <div class="me-2"><i class="bi bi-check2-circle text-success"></i></div>
                        <div class="small text-muted">Organize products into logical groups for easier tracking and reporting.</div>
                    </div>
                    <div class="d-flex mb-3">
                        <div class="me-2"><i class="bi bi-diagram-3 text-primary"></i></div>
                        <div class="small text-muted">Use sub-categories (Parents) to create hierarchies.</div>
                    </div>
                    <div class="d-flex mb-0">
                        <div class="me-2"><i class="bi bi-eye-slash text-danger"></i></div>
                        <div class="small text-muted">Inactive categories will not appear in product creation dropdowns.</div>
                    </div>
                </div>
            </div>
            
            <div class="card border-0 shadow-sm bg-primary text-white">
                <div class="card-body p-4 text-center">
                    <i class="bi bi-tags fs-1 mb-3 d-block opacity-50"></i>
                    <h6 class="fw-bold mb-2">Category Management</h6>
                    <p class="small mb-0 opacity-75">Maintain a clean hierarchy to improve inventory scanning and sales analytics.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="modalTitle">New Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="categoryForm">
                <div class="modal-body">
                    <input type="hidden" id="category_id" name="category_id">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="category_name" id="modal_category_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Parent Category</label>
                        <select class="form-select" name="parent_id" id="modal_parent_id">
                            <option value="0">None (Top Level)</option>
                            <?php foreach ($category_tree as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>">
                                    <?= str_repeat('&nbsp;&nbsp;', $cat['depth']) ?> <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                    <button type="submit" class="btn btn-primary">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    logReportAction('Viewed Categories Page', 'User viewed the product categories management page');

    // §UI-2 — DataTable. Ordering is disabled so the parent→child tree order
    // (and the S/NO sequence) is preserved; search + pagination still apply.
    if (!$.fn.DataTable.isDataTable('#categoriesTable')) {
        $('#categoriesTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            ordering: false,
            language: { emptyTable: 'No categories found.', zeroRecords: 'No matching categories.' }
        });
    }

    // §UI-3 — Select2 on the DB-backed Parent Category dropdown (inside modal).
    $('#categoryModal').on('shown.bs.modal', function () {
        if (!$('#modal_parent_id').hasClass('select2-hidden-accessible')) {
            $('#modal_parent_id').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#categoryModal'),
                placeholder: 'Select parent category...',
                width: '100%'
            });
        }
        // Sync Select2's display to whatever the native value was set to
        // by openAddModal() / edit (which run before the modal is shown).
        $('#modal_parent_id').trigger('change.select2');
    });
});

function openAddModal() {
    logReportAction('Viewed Create Category Modal', 'User opened the modal to create a new category');
    $('#categoryForm')[0].reset();
    $('#category_id').val('');
    $('#modalTitle').text('New Category');
    $('#categoryModal').modal('show');
}

$(document).on('click', '.btn-edit-cat', function() {
    const cat = $(this).data('cat');
    logReportAction('Viewed Edit Category Modal', 'User opened the modal to edit category: ' + cat.category_name);
    $('#modalTitle').text('Edit Category');
    $('#category_id').val(cat.category_id);
    $('#modal_category_name').val(cat.category_name);
    $('#modal_parent_id').val(cat.parent_id || 0);
    $('#modal_description').val(cat.description || '');
    $('#modal_status').val(cat.status || 'active');
    $('#categoryModal').modal('show');
});

$('#categoryForm').on('submit', function(e) {
    e.preventDefault();
    const formData = $(this).serialize();
    const categoryId = $('#category_id').val();
    const endpoint = categoryId ? '<?= getUrl('/api/update_category.php') ?>' : '<?= getUrl('/api/create_category.php') ?>';

    $.ajax({
        url: endpoint,
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                const action = categoryId ? 'Updated Product Category' : 'Created Product Category';
                const description = categoryId ? 'User updated category: ' + $('#modal_category_name').val() : 'User created a new category: ' + $('#modal_category_name').val();
                logReportAction(action, description);
                Swal.fire('Success', res.message || 'Category saved', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', res.message || 'Failed to save', 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'An error occurred during communication with the server.', 'error');
        }
    });
});

$(document).on('click', '.btn-delete-cat', function() {
    const id = $(this).data('id');
    Swal.fire({
        title: 'Are you sure?',
        text: "This will permanently delete the category!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('/api/delete_category.php') ?>',
                type: 'POST',
                data: { category_id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        logReportAction('Deleted Product Category', 'User deleted category ID: ' + id);
                        Swal.fire('Deleted!', 'Category has been deleted.', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
});
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
