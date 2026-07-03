<?php
// HR Performance & Development — Tier 3.
// One page, three tabs: Appraisals (Phase 3.3), Goals (Phase 3.4),
// Indicators-setup (Phase 3.2, this phase). Distinct from the business
// performance_dashboard report (D16). page_key: hr_performance.
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('hr_performance');

includeHeader();

require_once __DIR__ . '/includes/star_rating.php';

logActivity($pdo, $_SESSION['user_id'], 'View HR performance', 'User viewed the HR Performance page');

$can_edit = canEdit('hr_performance');

// Designations for the target matrix picker (in-scope by nature — company-wide lookup)
$designations = $pdo->query("SELECT designation_id, designation_name FROM designations WHERE status='active' ORDER BY designation_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<?php starRatingAssets(); ?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Performance &amp; Development</h4>
    </div>

    <ul class="nav nav-tabs mb-3" id="perfTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-appraisals" data-bs-toggle="tab" data-bs-target="#pane-appraisals" type="button" role="tab">
                <i class="bi bi-clipboard-check me-1"></i> Appraisals
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-goals" data-bs-toggle="tab" data-bs-target="#pane-goals" type="button" role="tab">
                <i class="bi bi-flag me-1"></i> Goals
            </button>
        </li>
        <?php if ($can_edit): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-indicators" data-bs-toggle="tab" data-bs-target="#pane-indicators" type="button" role="tab">
                <i class="bi bi-sliders me-1"></i> Indicators &amp; Targets
            </button>
        </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content">
        <!-- Appraisals (Phase 3.3) -->
        <div class="tab-pane fade show active" id="pane-appraisals" role="tabpanel">
            <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
                <i class="bi bi-clipboard-check fs-1 d-block mb-2"></i> Appraisals module — coming in this tier.
            </div></div>
        </div>

        <!-- Goals (Phase 3.4) -->
        <div class="tab-pane fade" id="pane-goals" role="tabpanel">
            <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
                <i class="bi bi-flag fs-1 d-block mb-2"></i> Goals module — coming in this tier.
            </div></div>
        </div>

        <?php if ($can_edit): ?>
        <!-- Indicators & Targets (Phase 3.2) -->
        <div class="tab-pane fade" id="pane-indicators" role="tabpanel">
            <div class="row g-3">
                <!-- Left: categories + indicators -->
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-list-check me-1"></i> Indicators</h6>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="openCategoryModal()"><i class="bi bi-folder-plus"></i> Category</button>
                                <button class="btn btn-primary" onclick="openIndicatorModal()"><i class="bi bi-plus-circle"></i> Indicator</button>
                            </div>
                        </div>
                        <div class="card-body" id="indicatorList">
                            <div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span> Loading...</div>
                        </div>
                    </div>
                </div>

                <!-- Right: designation target matrix -->
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h6 class="mb-0"><i class="bi bi-bullseye me-1"></i> Competency Targets by Designation</h6>
                        </div>
                        <div class="card-body">
                            <div class="mb-3" style="max-width:360px">
                                <label class="form-label small mb-1">Designation</label>
                                <select id="matrix_designation" class="form-select">
                                    <option value="">Select a designation…</option>
                                    <?php foreach ($designations as $d): ?>
                                    <option value="<?= (int)$d['designation_id'] ?>"><?= safe_output($d['designation_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <form id="matrixForm">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="designation_id" id="matrix_designation_hidden">
                                <div id="matrixBody">
                                    <p class="text-muted">Pick a designation to set its expected competency ratings.</p>
                                </div>
                                <div class="text-end mt-3 d-none" id="matrixSaveRow">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save Targets</button>
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

<?php if ($can_edit): ?>
<!-- Category modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-folder me-1"></i> <span id="categoryModalTitle">Add Category</span></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="categoryForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="category_id" id="cat_id">
                <div class="mb-3">
                    <label class="form-label">Category Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="category_name" id="cat_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Sort Order</label>
                    <input type="number" class="form-control" name="sort_order" id="cat_sort" value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Indicator modal -->
<div class="modal fade" id="indicatorModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-check2-square me-1"></i> <span id="indicatorModalTitle">Add Indicator</span></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="indicatorForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="indicator_id" id="ind_id">
                <div class="mb-3">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <select class="form-select" name="category_id" id="ind_category" required></select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Indicator Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="indicator_name" id="ind_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" id="ind_desc" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<script>
const HP_CSRF = <?= json_encode(csrf_token()) ?>;
<?php if ($can_edit): ?>
let CATS = [], INDS = [];

function loadIndicators() {
    $.getJSON('<?= buildUrl('api/get_indicators.php') ?>', function (res) {
        if (!res.success) { $('#indicatorList').html('<div class="text-danger">Could not load.</div>'); return; }
        CATS = res.categories; INDS = res.indicators;
        // category options for the indicator modal
        $('#ind_category').html(CATS.map(c => `<option value="${c.category_id}">${safeOutput(c.category_name)}</option>`).join(''));
        // grouped list
        if (!CATS.length) { $('#indicatorList').html('<p class="text-muted mb-0">No categories yet. Add one to begin.</p>'); return; }
        let html = '';
        CATS.forEach(c => {
            const rows = INDS.filter(i => Number(i.category_id) === Number(c.category_id));
            html += `<div class="mb-3">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-2">
                    <strong>${safeOutput(c.category_name)}</strong>
                    <span class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-link p-0 me-2" onclick="openCategoryModal(${c.category_id})" title="Rename"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-link text-danger p-0" onclick="deleteCategory(${c.category_id})" title="Delete"><i class="bi bi-trash"></i></button>
                    </span>
                </div>`;
            if (!rows.length) {
                html += '<div class="small text-muted mb-2">No indicators.</div>';
            } else {
                html += '<ul class="list-unstyled mb-0">';
                rows.forEach(i => {
                    html += `<li class="d-flex justify-content-between align-items-center py-1">
                        <span>${safeOutput(i.indicator_name)}${i.description ? ` <small class="text-muted">— ${safeOutput(i.description)}</small>` : ''}</span>
                        <span class="btn-group btn-group-sm">
                            <button class="btn btn-sm btn-link p-0 me-2" onclick="openIndicatorModal(${i.indicator_id})" title="Edit"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-link text-danger p-0" onclick="deleteIndicator(${i.indicator_id})" title="Remove"><i class="bi bi-trash"></i></button>
                        </span>
                    </li>`;
                });
                html += '</ul>';
            }
            html += '</div>';
        });
        $('#indicatorList').html(html);
    });
}

function post(action, data, done) {
    $.post('<?= buildUrl('api/manage_indicators.php') ?>', Object.assign({ action, _csrf: HP_CSRF }, data), function (r) {
        if (r.success) { done && done(r); loadIndicators(); }
        else Swal.fire({ icon: 'error', title: 'Error', text: r.message });
    }, 'json').fail(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }));
}

window.openCategoryModal = function (id) {
    $('#categoryForm')[0].reset(); $('#cat_id').val('');
    if (id) {
        const c = CATS.find(x => Number(x.category_id) === id);
        $('#categoryModalTitle').text('Rename Category'); $('#cat_id').val(id);
        $('#cat_name').val(c ? c.category_name : ''); $('#cat_sort').val(c ? c.sort_order : 0);
    } else { $('#categoryModalTitle').text('Add Category'); }
    new bootstrap.Modal('#categoryModal').show();
};
$('#categoryForm').on('submit', function (e) {
    e.preventDefault();
    const id = $('#cat_id').val();
    const data = { category_name: $('#cat_name').val(), sort_order: $('#cat_sort').val() };
    if (id) data.category_id = id;
    post(id ? 'rename_category' : 'add_category', data, () => bootstrap.Modal.getInstance(document.getElementById('categoryModal')).hide());
});
window.deleteCategory = function (id) {
    Swal.fire({ title: 'Delete category?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
        .then(r => { if (r.isConfirmed) post('delete_category', { category_id: id }); });
};

window.openIndicatorModal = function (id) {
    $('#indicatorForm')[0].reset(); $('#ind_id').val('');
    if (id) {
        const i = INDS.find(x => Number(x.indicator_id) === id);
        $('#indicatorModalTitle').text('Edit Indicator'); $('#ind_id').val(id);
        if (i) { $('#ind_category').val(i.category_id); $('#ind_name').val(i.indicator_name); $('#ind_desc').val(i.description || ''); }
    } else { $('#indicatorModalTitle').text('Add Indicator'); }
    new bootstrap.Modal('#indicatorModal').show();
};
$('#indicatorForm').on('submit', function (e) {
    e.preventDefault();
    const id = $('#ind_id').val();
    const data = { category_id: $('#ind_category').val(), indicator_name: $('#ind_name').val(), description: $('#ind_desc').val() };
    if (id) data.indicator_id = id;
    post(id ? 'update_indicator' : 'add_indicator', data, () => bootstrap.Modal.getInstance(document.getElementById('indicatorModal')).hide());
});
window.deleteIndicator = function (id) {
    Swal.fire({ title: 'Remove indicator?', text: 'Past appraisals keep their recorded scores.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Remove' })
        .then(r => { if (r.isConfirmed) post('delete_indicator', { indicator_id: id }); });
};

// ── Target matrix ──────────────────────────────────────────────────────────
function starRow(name, value, expected) {
    let h = `<span class="star-rating" data-name="${name}"><input type="hidden" name="${name}" value="${value || 0}">`;
    for (let i = 1; i <= 5; i++) h += `<button type="button" class="star${i <= (value||0) ? ' filled' : ''}" data-val="${i}" title="${i}">&#9733;</button>`;
    h += '<span class="star-clear" title="Clear">clear</span></span>';
    return h;
}
$('#matrix_designation').on('change', function () {
    const did = $(this).val();
    $('#matrix_designation_hidden').val(did);
    if (!did) { $('#matrixBody').html('<p class="text-muted">Pick a designation to set its expected competency ratings.</p>'); $('#matrixSaveRow').addClass('d-none'); return; }
    $('#matrixBody').html('<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span></div>');
    $.getJSON('<?= buildUrl('api/get_indicators.php') ?>', { designation_id: did }, function (res) {
        if (!res.success) { $('#matrixBody').html('<div class="text-danger">Could not load.</div>'); return; }
        if (!res.indicators.length) { $('#matrixBody').html('<p class="text-muted mb-0">Add indicators first (left panel).</p>'); $('#matrixSaveRow').addClass('d-none'); return; }
        let html = '';
        res.categories.forEach(c => {
            const rows = res.indicators.filter(i => Number(i.category_id) === Number(c.category_id));
            if (!rows.length) return;
            html += `<div class="mb-2"><div class="small text-uppercase text-muted fw-semibold mb-1">${safeOutput(c.category_name)}</div>`;
            rows.forEach(i => {
                const cur = res.targets[i.indicator_id] || 0;
                html += `<div class="d-flex justify-content-between align-items-center py-1">
                    <span>${safeOutput(i.indicator_name)}</span>${starRow('target[' + i.indicator_id + ']', cur)}</div>`;
            });
            html += '</div>';
        });
        $('#matrixBody').html(html);
        $('#matrixSaveRow').removeClass('d-none');
    });
});
$('#matrixForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'); const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    $.ajax({ url: '<?= buildUrl('api/save_designation_targets.php') ?>', type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
        success: r => r.success
            ? Swal.fire({ icon: 'success', title: 'Saved!', text: r.message, timer: 1600, showConfirmButton: false })
            : Swal.fire({ icon: 'error', title: 'Error', text: r.message }),
        error: () => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }),
        complete: () => btn.prop('disabled', false).html(orig)
    });
});

$(document).ready(loadIndicators);
<?php endif; ?>
</script>

<?php includeFooter(); ?>
