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
                            <th>Prefix</th>
                            <th>TRA Class</th>
                            <th>Book Method</th>
                            <th class="text-end">Useful Life</th>
                            <th class="text-end">RB Rate</th>
                            <th class="text-end">Tax %</th>
                            <th class="text-end">Salvage %</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="categoriesBody">
                        <tr><td colspan="10" class="text-center py-4 text-muted">Loading…</td></tr>
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
                        <!-- Identification -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
                            <input type="text" name="category_name" id="cm_name" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Code Prefix</label>
                            <input type="text" name="code_prefix" id="cm_prefix" class="form-control text-uppercase" maxlength="10" placeholder="e.g. COMP">
                            <small class="text-muted">Asset codes → COMP-0001</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">TRA Class</label>
                            <input type="text" name="tra_class" id="cm_tra_class" class="form-control" placeholder="e.g. Class 4">
                        </div>

                        <!-- Depreciable toggle -->
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_depreciable" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_depreciable" id="cm_is_depreciable" value="1" checked onchange="toggleDepreciable()">
                                <label class="form-check-label fw-semibold" for="cm_is_depreciable">Depreciable</label>
                                <small class="d-block text-muted">Turn off for Land and other non-depreciable assets — they show cost only on the PPE schedule.</small>
                            </div>
                        </div>

                        <!-- Book depreciation defaults -->
                        <div class="col-12" id="cm_depreciation_section">
                            <div class="border rounded p-3 bg-light">
                                <h6 class="text-uppercase small fw-bold text-muted mb-3"><i class="bi bi-journal-text me-1"></i> Book Area (financial statements)</h6>
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Book Method <span class="text-danger">*</span></label>
                                        <select name="default_method" id="cm_method" class="form-select" onchange="toggleDepreciable()">
                                            <option value="straight_line">Straight Line</option>
                                            <option value="reducing_balance">Reducing Balance</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3" id="cm_life_group">
                                        <label class="form-label fw-semibold">Useful Life (years)</label>
                                        <input type="number" name="default_useful_life_years" id="cm_life" class="form-control" min="1">
                                        <small class="text-muted">For Straight Line</small>
                                    </div>
                                    <div class="col-md-3" id="cm_rate_group">
                                        <label class="form-label fw-semibold">RB Rate (%)</label>
                                        <input type="number" name="default_annual_rate_percent" id="cm_rate" class="form-control" step="0.01" min="0" max="100">
                                        <small class="text-muted">For Reducing Balance</small>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label fw-semibold">Salvage Value (%)</label>
                                        <input type="number" name="default_salvage_percent" id="cm_salvage" class="form-control" step="0.01" min="0" max="100" value="0">
                                    </div>
                                </div>
                                <h6 class="text-uppercase small fw-bold text-muted mt-3 mb-2"><i class="bi bi-bank me-1"></i> Tax Area (capital allowances)</h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Tax Rate (%) <span class="text-danger">*</span></label>
                                        <input type="number" name="tax_rate" id="cm_tax_rate" class="form-control" step="0.01" min="0" max="100">
                                        <small class="text-muted">Statutory ITA reducing-balance rate</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- GL determination -->
                        <div class="col-12">
                            <div class="border rounded p-3">
                                <h6 class="text-uppercase small fw-bold text-muted mb-3"><i class="bi bi-diagram-3 me-1"></i> GL Account Determination</h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Asset Account</label>
                                        <input type="text" name="gl_asset_account" id="cm_gl_asset" class="form-control" placeholder="e.g. 1500">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Accum. Dep. Account</label>
                                        <input type="text" name="gl_accum_account" id="cm_gl_accum" class="form-control" placeholder="e.g. 1510">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Dep. Expense Account</label>
                                        <input type="text" name="gl_expense_account" id="cm_gl_expense" class="form-control" placeholder="e.g. 7200">
                                    </div>
                                </div>
                            </div>
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

<!-- View Details Modal -->
<div class="modal fade" id="categoryViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i> Category Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="categoryViewBody">
                <!-- populated by viewCategoryDetails() -->
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const CAT_CAN_EDIT   = <?= json_encode(canEdit('assets')) ?>;
const CAT_CAN_DELETE = <?= json_encode(canDelete('assets')) ?>;

$(document).ready(function() {
    loadCategories();

    // Table uses scrollX; let the action dropdown escape the horizontal-scroll
    // container instead of being clipped by its overflow.
    $(document)
        .on('show.bs.dropdown', '#assetCategoriesTable', function() {
            $(this).closest('.dataTables_scrollBody').css('overflow', 'visible');
            $(this).closest('.table-responsive').css('overflow', 'visible');
        })
        .on('hide.bs.dropdown', '#assetCategoriesTable', function() {
            $(this).closest('.dataTables_scrollBody').css('overflow', '');
            $(this).closest('.table-responsive').css('overflow', '');
        });
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
            $('#categoriesBody').html('<tr><td colspan="10" class="text-danger text-center py-3">' + (resp.message || 'Failed') + '</td></tr>');
            return;
        }
        if (!resp.categories.length) {
            $('#categoriesBody').html('<tr><td colspan="10" class="text-muted text-center py-4">No categories yet.</td></tr>');
            return;
        }
        let html = '';
        resp.categories.forEach(c => {
            const dep = Number(c.is_depreciable) === 1;
            html += `<tr data-cat='${JSON.stringify(c)}'>
                <td class="ps-4 fw-semibold">${escapeHtml(c.category_name)}
                    ${dep ? '' : '<span class="badge bg-info-subtle text-info-emphasis border ms-1">Non-depreciable</span>'}</td>
                <td><span class="badge bg-dark-subtle text-dark border">${escapeHtml(c.code_prefix || '—')}</span></td>
                <td><span class="badge bg-light text-dark border">${escapeHtml(c.tra_class || '—')}</span></td>
                <td>${dep ? (c.default_method === 'straight_line' ? 'Straight Line' : 'Reducing Balance') : '—'}</td>
                <td class="text-end">${dep ? (c.default_useful_life_years ?? '—') : '—'}</td>
                <td class="text-end">${dep && c.default_annual_rate_percent !== null ? c.default_annual_rate_percent + '%' : '—'}</td>
                <td class="text-end">${dep && c.tax_rate !== null ? c.tax_rate + '%' : '—'}</td>
                <td class="text-end">${dep ? c.default_salvage_percent + '%' : '—'}</td>
                <td><span class="badge bg-${c.status === 'active' ? 'success' : 'secondary'}">${c.status}</span></td>
                <td class="text-end pe-4">${categoryActions(c)}</td>
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
        $('#cm_prefix').val(cat.code_prefix || '');
        $('#cm_tra_class').val(cat.tra_class || '');
        $('#cm_is_depreciable').prop('checked', Number(cat.is_depreciable) === 1);
        $('#cm_method').val(cat.default_method);
        $('#cm_life').val(cat.default_useful_life_years || '');
        $('#cm_rate').val(cat.default_annual_rate_percent || '');
        $('#cm_tax_rate').val(cat.tax_rate ?? '');
        $('#cm_salvage').val(cat.default_salvage_percent);
        $('#cm_gl_asset').val(cat.gl_asset_account || '');
        $('#cm_gl_accum').val(cat.gl_accum_account || '');
        $('#cm_gl_expense').val(cat.gl_expense_account || '');
        $('#cm_status').val(cat.status);
        $('#cm_desc').val(cat.description || '');
    } else {
        $('#categoryModalTitle').text('Add Category');
        $('#categoryForm')[0].reset();
        $('#cm_category_id').val('');
        $('#cm_is_depreciable').prop('checked', true);
        $('#cm_salvage').val('0');
        $('#cm_status').val('active');
    }
    toggleDepreciable();
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}

// Show/hide depreciation defaults based on the Depreciable switch, and show
// the relevant book-method field (useful life for SL, RB rate for RB).
function toggleDepreciable() {
    const dep = $('#cm_is_depreciable').is(':checked');
    $('#cm_depreciation_section').toggle(dep);
    if (dep) {
        const isSL = $('#cm_method').val() === 'straight_line';
        $('#cm_life_group').toggle(isSL);
        $('#cm_rate_group').toggle(!isSL);
    }
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

// Build the Actions dropdown (gear + caret) for a category row.
function categoryActions(c) {
    const json = JSON.stringify(c).replace(/'/g, '&#39;');
    let items = `<li><a class="dropdown-item" href="javascript:void(0)" onclick='viewCategoryDetails(${json})'>
                    <i class="bi bi-eye text-primary me-2"></i> View Details</a></li>`;
    if (CAT_CAN_EDIT) {
        items += `<li><a class="dropdown-item" href="javascript:void(0)" onclick='openCategoryModal(${json})'>
                    <i class="bi bi-pencil text-info me-2"></i> Edit</a></li>`;
    }
    if (CAT_CAN_DELETE) {
        items += `<li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick='deleteCategory(${json})'>
                    <i class="bi bi-trash me-2"></i> Delete</a></li>`;
    }
    return `<div class="btn-group">
                <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle"
                        data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" title="Actions">
                    <i class="bi bi-gear"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">${items}</ul>
            </div>`;
}

// Read-only details popup.
function viewCategoryDetails(c) {
    const dep = Number(c.is_depreciable) === 1;
    const row = (label, val) => `
        <div class="col-md-6 mb-3">
            <div class="text-uppercase small fw-bold text-muted">${label}</div>
            <div>${val}</div>
        </div>`;
    let html = `<div class="row">`;
    html += row('Category Name', escapeHtml(c.category_name));
    html += row('Code Prefix', escapeHtml(c.code_prefix || '—'));
    html += row('TRA Class', escapeHtml(c.tra_class || '—'));
    html += row('Depreciable', dep ? 'Yes' : 'No');
    if (dep) {
        html += row('Book Method', c.default_method === 'straight_line' ? 'Straight Line' : 'Reducing Balance');
        html += row('Useful Life (years)', c.default_useful_life_years ?? '—');
        html += row('RB Rate', c.default_annual_rate_percent !== null ? c.default_annual_rate_percent + '%' : '—');
        html += row('Tax Rate', c.tax_rate !== null ? c.tax_rate + '%' : '—');
        html += row('Salvage Value', c.default_salvage_percent + '%');
    }
    html += row('GL Asset Account', escapeHtml(c.gl_asset_account || '—'));
    html += row('GL Accum. Dep. Account', escapeHtml(c.gl_accum_account || '—'));
    html += row('GL Dep. Expense Account', escapeHtml(c.gl_expense_account || '—'));
    html += row('Status', escapeHtml(c.status));
    html += `<div class="col-12 mb-1">
                <div class="text-uppercase small fw-bold text-muted">Description</div>
                <div>${escapeHtml(c.description || '—')}</div>
             </div>`;
    html += `</div>`;
    $('#categoryViewBody').html(html);
    new bootstrap.Modal(document.getElementById('categoryViewModal')).show();
}

// Soft-delete a category (blocked server-side if assets still reference it).
function deleteCategory(c) {
    Swal.fire({
        title: 'Delete category?',
        html: `Delete <strong>${escapeHtml(c.category_name)}</strong>? It can only be removed if no assets are assigned to it.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/assets/delete_asset_category.php') ?>',
            type: 'POST',
            data: { category_id: c.category_id },
            dataType: 'json',
            success: function(resp) {
                if (resp.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted', text: resp.message, timer: 1800, showConfirmButton: false });
                    loadCategories();
                } else {
                    Swal.fire('Cannot delete', resp.message, 'error');
                }
            },
            error: function(xhr) {
                let msg = 'Server error';
                try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch(e) {}
                Swal.fire('Error', msg, 'error');
            }
        });
    });
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}
</script>

<?php includeFooter(); ?>
