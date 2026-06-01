<?php
/**
 * app/constant/settings/asset_categories.php
 *
 * Admin page to manage asset categories — used by the depreciation engine
 * to auto-fill useful life / method / salvage % when a category is picked
 * on the asset form.
 *
 * Permission: canCreate('assets') / canEdit('assets'). Admin always.
 */
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('assets');

// View-page activity log (security-coverage audit requires this).
if (function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed Asset Categories',
        ($_SESSION['username'] ?? 'User') . ' opened the Asset Categories settings page.');
}

includeHeader();
?>

<div class="container-fluid py-4">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('settings') ?>">Settings</a></li>
            <li class="breadcrumb-item active">Asset Categories</li>
        </ol>
    </nav>

    <div class="row mb-3 align-items-center">
        <div class="col-md-8">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-tags me-2"></i> Asset Categories</h2>
            <p class="text-muted small mb-0">Defaults used when creating assets — method, useful life, salvage % auto-fill from the chosen category.</p>
        </div>
        <div class="col-md-4 text-end">
            <?php if (canCreate('assets')): ?>
                <button class="btn btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="openCategoryModal(null)">
                    <i class="bi bi-plus-lg me-1"></i> Add Category
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="assetCategoriesTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="bg-light text-uppercase small fw-bold text-muted">
                        <tr>
                            <th class="ps-4">Category</th>
                            <th>TRA Class</th>
                            <th>Default Method</th>
                            <th class="text-end">Useful Life</th>
                            <th class="text-end">RB Rate</th>
                            <th class="text-end">Salvage %</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="categoriesBody">
                        <tr><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="categoryForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-tag me-2"></i> <span id="categoryModalTitle">Add Category</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="category_id" id="cm_category_id">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
                            <input type="text" name="category_name" id="cm_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">TRA Class</label>
                            <input type="text" name="tra_class" id="cm_tra_class" class="form-control" placeholder="e.g. Class 4">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Default Method <span class="text-danger">*</span></label>
                            <select name="default_method" id="cm_method" class="form-select" required>
                                <option value="straight_line">Straight Line</option>
                                <option value="reducing_balance">Reducing Balance</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Useful Life (years)</label>
                            <input type="number" name="default_useful_life_years" id="cm_life" class="form-control" min="1">
                            <small class="text-muted">Used by Straight Line</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">RB Rate (%)</label>
                            <input type="number" name="default_annual_rate_percent" id="cm_rate" class="form-control" step="0.01" min="0" max="100">
                            <small class="text-muted">Used by Reducing Balance</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Salvage Value (%)</label>
                            <input type="number" name="default_salvage_percent" id="cm_salvage" class="form-control" step="0.01" min="0" max="100" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" id="cm_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" id="cm_desc" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    loadCategories();
    $('#categoryForm').on('submit', function(e) {
        e.preventDefault();
        saveCategory();
    });
});

function loadCategories() {
    $.getJSON('<?= buildUrl('api/assets/get_asset_categories.php') ?>', { include_archived: 1 }, function(resp) {
        // §UI-2 — destroy any existing DataTable before the AJAX loader replaces
        // the tbody, then re-init on the fresh rows (success branch only).
        if ($.fn.DataTable.isDataTable('#assetCategoriesTable')) {
            $('#assetCategoriesTable').DataTable().clear().destroy();
        }
        if (!resp.success) {
            $('#categoriesBody').html('<tr><td colspan="8" class="text-danger text-center py-3">' + (resp.message || 'Failed') + '</td></tr>');
            return;
        }
        if (!resp.categories.length) {
            $('#categoriesBody').html('<tr><td colspan="8" class="text-muted text-center py-4">No categories yet.</td></tr>');
            return;
        }
        let html = '';
        resp.categories.forEach(c => {
            html += `<tr data-cat='${JSON.stringify(c)}'>
                <td class="ps-4 fw-semibold">${escapeHtml(c.category_name)}</td>
                <td><span class="badge bg-light text-dark border">${escapeHtml(c.tra_class || '—')}</span></td>
                <td>${c.default_method === 'straight_line' ? 'Straight Line' : 'Reducing Balance'}</td>
                <td class="text-end">${c.default_useful_life_years ?? '—'}</td>
                <td class="text-end">${c.default_annual_rate_percent !== null ? c.default_annual_rate_percent + '%' : '—'}</td>
                <td class="text-end">${c.default_salvage_percent}%</td>
                <td><span class="badge bg-${c.status === 'active' ? 'success' : 'secondary'}">${c.status}</span></td>
                <td class="text-end pe-4">
                    <button class="btn btn-sm btn-outline-primary" onclick='openCategoryModal(${JSON.stringify(c)})' title="Edit"><i class="bi bi-pencil"></i></button>
                </td>
            </tr>`;
        });
        $('#categoriesBody').html(html);

        // §UI-2 — init DataTable on the freshly rendered rows. Actions col last.
        $('#assetCategoriesTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: { emptyTable: 'No categories yet.', zeroRecords: 'No matching categories.' }
        });
    });
}

function openCategoryModal(cat) {
    if (cat) {
        $('#categoryModalTitle').text('Edit Category');
        $('#cm_category_id').val(cat.category_id);
        $('#cm_name').val(cat.category_name);
        $('#cm_tra_class').val(cat.tra_class || '');
        $('#cm_method').val(cat.default_method);
        $('#cm_life').val(cat.default_useful_life_years || '');
        $('#cm_rate').val(cat.default_annual_rate_percent || '');
        $('#cm_salvage').val(cat.default_salvage_percent);
        $('#cm_status').val(cat.status);
        $('#cm_desc').val(cat.description || '');
    } else {
        $('#categoryModalTitle').text('Add Category');
        $('#categoryForm')[0].reset();
        $('#cm_category_id').val('');
        $('#cm_salvage').val('0');
        $('#cm_status').val('active');
    }
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

function saveCategory() {
    const fd = new FormData($('#categoryForm')[0]);
    $.ajax({
        url: '<?= buildUrl('api/assets/save_asset_category.php') ?>',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                bootstrap.Modal.getInstance(document.getElementById('categoryModal')).hide();
                Swal.fire('Saved', resp.message, 'success');
                loadCategories();
            } else {
                Swal.fire('Error', resp.message, 'error');
            }
        },
        error: function(xhr) {
            let msg = 'Server error';
            try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch(e) {}
            Swal.fire('Error', msg, 'error');
        }
    });
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}
</script>

<?php includeFooter(); ?>
