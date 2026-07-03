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

$can_edit    = canEdit('hr_performance');
$can_create  = canCreate('hr_performance');
$can_submit  = canSubmit('hr_performance');
$can_approve = canApprove('hr_performance');
$can_reject  = canReject('hr_performance');
$can_recommend = canCreate('employee_lifecycle');   // D20

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
            <div class="d-flex justify-content-end gap-2 mb-3">
                <?php if ($can_edit): ?>
                <button class="btn btn-outline-secondary" onclick="openCyclesModal()"><i class="bi bi-calendar-range me-1"></i> Cycles</button>
                <?php endif; ?>
                <?php if ($can_create): ?>
                <button class="btn btn-primary" onclick="openAppraisalModal()"><i class="bi bi-plus-circle me-1"></i> New Appraisal</button>
                <?php endif; ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#e9ecef;border:1px solid #dee2e6"><div class="fs-4 fw-bold text-secondary" id="st_draft">0</div><div class="small text-muted">Draft</div></div></div>
                <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#fff3cd;border:1px solid #ffe69c"><div class="fs-4 fw-bold text-warning" id="st_submitted">0</div><div class="small text-muted">Submitted</div></div></div>
                <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><div class="fs-4 fw-bold text-primary" id="st_approved">0</div><div class="small text-muted">Approved</div></div></div>
                <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#d1e7dd;border:1px solid #a3cfbb"><div class="fs-4 fw-bold text-success" id="st_avg">—</div><div class="small text-muted">Avg rating</div></div></div>
            </div>

            <div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">Cycle</label>
                        <select class="form-select form-select-sm" id="af_cycle"><option value="">All cycles</option></select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1">Status</label>
                        <select class="form-select form-select-sm" id="af_status">
                            <option value="">All statuses</option>
                            <option value="draft">Draft</option><option value="submitted">Submitted</option>
                            <option value="approved">Approved</option><option value="rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small mb-1">Employee</label>
                        <select class="form-select form-select-sm" id="af_employee"><option value="">All employees</option></select>
                    </div>
                    <div class="col-12 col-md-2">
                        <button class="btn btn-sm btn-outline-secondary w-100" id="af_reset"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                </div>
            </div></div>

            <div id="apTableView" class="card border-0 shadow-sm"><div class="card-body">
                <table id="appraisalsTable" class="table table-hover align-middle w-100">
                    <thead class="table-dark"><tr>
                        <th>Date</th><th>Employee</th><th>Cycle</th><th>Designation</th><th>Overall</th><th>Status</th><th class="text-end">Actions</th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
            </div></div>
            <div id="apCardView" class="row g-2 d-none"></div>
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

<!-- View Appraisal (scorecard) modal — visible to all viewers, print-friendly -->
<div class="modal fade" id="appraisalViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-clipboard-check me-1"></i> Appraisal Scorecard</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="appraisalViewBody"></div>
        <div class="modal-footer d-print-none" id="appraisalViewFooter">
            <button type="button" class="btn btn-outline-secondary" onclick="printScorecard()"><i class="bi bi-printer me-1"></i> Print</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
    </div></div>
</div>

<?php if ($can_create): ?>
<!-- New Appraisal modal -->
<div class="modal fade" id="appraisalModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> New Appraisal</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="appraisalForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Cycle <span class="text-danger">*</span></label>
                        <select class="form-select" name="cycle_id" id="ap_cycle" required></select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Employee <span class="text-danger">*</span></label>
                        <select class="form-select" name="employee_id" id="ap_employee" required></select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="appraisal_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div id="ap_items" class="mt-3">
                    <p class="text-muted">Pick an employee to load the competency indicators.</p>
                </div>
                <div class="mt-3">
                    <label class="form-label">Summary Remarks</label>
                    <textarea class="form-control" name="remarks" rows="2" placeholder="Overall appraiser summary"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-outline-primary" name="mode" value="draft"><i class="bi bi-save me-1"></i> Save Draft</button>
                <?php if ($can_submit): ?>
                <button type="submit" class="btn btn-primary" name="mode" value="submit"><i class="bi bi-send me-1"></i> Submit</button>
                <?php endif; ?>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php if ($can_edit): ?>
<!-- Cycles management modal -->
<div class="modal fade" id="cyclesModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-calendar-range me-1"></i> Appraisal Cycles</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <form id="cycleForm" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="cycle_id" id="cy_id">
                <div class="col-md-4"><label class="form-label small mb-1">Name</label><input class="form-control form-control-sm" name="cycle_name" id="cy_name" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" name="period_from" id="cy_from" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" name="period_to" id="cy_to" required></div>
                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit"><i class="bi bi-check-circle"></i> Save</button></div>
            </form>
            <div id="cyclesList"><div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span></div></div>
        </div>
    </div></div>
</div>
<?php endif; ?>

<?php if ($can_recommend) { require __DIR__ . '/includes/lifecycle_modal.php'; } ?>

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

// ═══ Appraisals (Phase 3.3) — available to all viewers ═══════════════════════
const AP_CAN_CREATE  = <?= json_encode($can_create) ?>;
const AP_CAN_SUBMIT  = <?= json_encode($can_submit) ?>;
const AP_CAN_APPROVE = <?= json_encode($can_approve) ?>;
const AP_CAN_REJECT  = <?= json_encode($can_reject) ?>;
const AP_CAN_EDIT    = <?= json_encode($can_edit) ?>;
const AP_CAN_RECOMMEND = <?= json_encode($can_recommend) ?>;
const AP_MY_ID       = <?= (int)$_SESSION['user_id'] ?>;
let apTable = null, AP_ROWS = [], AP_CYCLES = [];

function starsInline(v, expected) {
    v = parseInt(v, 10) || 0;
    let h = '<span style="white-space:nowrap">';
    for (let i = 1; i <= 5; i++) {
        const filled = i <= v;
        const mark = (expected && i === expected) ? ';border-bottom:2px solid #0d6efd' : '';
        h += `<span style="color:${filled ? '#f5b301' : '#ced4da'}${mark}">&#9733;</span>`;
    }
    return h + '</span>';
}
function apStatusBadge(s) {
    const map = { draft:['#e9ecef','#495057'], submitted:['#fff3cd','#664d03'], approved:['#0d6efd','#fff'], rejected:['#dc3545','#fff'] };
    const [bg, fg] = map[s] || ['#e9ecef','#495057'];
    return `<span class="badge" style="background:${bg};color:${fg}">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`;
}
function apActions(r) {
    let items = `<li><button class="dropdown-item py-2" onclick="viewAppraisal(${r.appraisal_id})"><i class="bi bi-eye text-primary me-2"></i>View</button></li>`;
    if (r.status === 'draft' && AP_CAN_SUBMIT) items += `<li><button class="dropdown-item py-2" onclick="apAction(${r.appraisal_id},'submit')"><i class="bi bi-send text-primary me-2"></i>Submit</button></li>`;
    if (r.status === 'submitted' && AP_CAN_APPROVE) items += `<li><button class="dropdown-item py-2" onclick="apAction(${r.appraisal_id},'approve')"><i class="bi bi-check2-all text-primary me-2"></i>Approve</button></li>`;
    if (r.status === 'submitted' && AP_CAN_REJECT) items += `<li><button class="dropdown-item py-2 text-danger" onclick="apAction(${r.appraisal_id},'reject')"><i class="bi bi-slash-circle text-danger me-2"></i>Reject</button></li>`;
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle px-2" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
}
function loadAppraisals() {
    const p = { cycle_id: $('#af_cycle').val() || '', status: $('#af_status').val(), employee_id: $('#af_employee').val() || '' };
    $.getJSON('<?= buildUrl('api/get_appraisals.php') ?>', p, function (res) {
        if (!res.success) { Swal.fire({ icon:'error', title:'Error', text:res.message || 'Could not load.' }); return; }
        AP_ROWS = res.data;
        $('#st_draft').text(res.stats.draft); $('#st_submitted').text(res.stats.submitted);
        $('#st_approved').text(res.stats.approved); $('#st_avg').text(res.stats.avg !== null ? res.stats.avg : '—');
        const data = res.data.map(r => [
            safeOutput(r.appraisal_date),
            `<a href="<?= getUrl('employee_details') ?>?id=${r.employee_id}" class="text-decoration-none fw-semibold">${safeOutput(r.first_name+' '+r.last_name)}</a>`,
            safeOutput(r.cycle_name), safeOutput(r.designation_name || '—'),
            r.overall_rating !== null ? starsInline(Math.round(r.overall_rating)) + ` <small class="text-muted">${Number(r.overall_rating).toFixed(2)}</small>` : '<span class="text-muted">—</span>',
            apStatusBadge(r.status), apActions(r)
        ]);
        apTable.clear().rows.add(data).draw();
    });
}
function apCards(rows) {
    if (!rows.length) { $('#apCardView').html('<div class="col-12 text-center py-5 text-muted">No appraisals yet.</div>'); return; }
    let html = '';
    rows.forEach(r => {
        html += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
            <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(r.first_name+' '+r.last_name)}</div>${apStatusBadge(r.status)}</div>
            <div class="small text-muted mt-1">${safeOutput(r.cycle_name)} · ${safeOutput(r.appraisal_date)}</div>
            <div class="mt-1">${r.overall_rating !== null ? starsInline(Math.round(r.overall_rating)) : '<span class="text-muted small">Not rated</span>'}</div>
            <button class="btn btn-sm btn-outline-primary mt-2" onclick="viewAppraisal(${r.appraisal_id})"><i class="bi bi-eye"></i> View</button>
        </div></div></div>`;
    });
    $('#apCardView').html(html);
}

function viewAppraisal(id) {
    $.getJSON('<?= buildUrl('api/get_appraisal.php') ?>', { appraisal_id: id }, function (res) {
        if (!res.success) { Swal.fire({ icon:'error', title:'Error', text:res.message }); return; }
        const a = res.data;
        let rows = '', lastCat = null, catSum = {}, catCnt = {};
        res.items.forEach(it => {
            if (it.category_name !== lastCat) { rows += `<tr class="table-light"><td colspan="3" class="fw-semibold small text-uppercase">${safeOutput(it.category_name || 'Uncategorised')}</td></tr>`; lastCat = it.category_name; }
            rows += `<tr><td>${safeOutput(it.indicator_name || ('#'+it.indicator_id))}${it.comment ? `<br><small class="text-muted">${safeOutput(it.comment)}</small>` : ''}</td>
                <td>${it.expected_rating ? starsInline(it.expected_rating) : '<span class="text-muted small">—</span>'}</td>
                <td>${starsInline(it.actual_rating, it.expected_rating ? Number(it.expected_rating) : null)}</td></tr>`;
        });
        let recommend = '';
        if (a.status === 'approved' && AP_CAN_RECOMMEND) {
            recommend = `<div class="d-print-none mt-2">
                <button class="btn btn-sm btn-outline-primary" onclick="recommend('promotion', ${a.employee_id}, '${safeOutput(a.first_name+' '+a.last_name).replace(/'/g,"\\'")}', '${safeOutput(a.cycle_name).replace(/'/g,"\\'")}', ${a.overall_rating})"><i class="bi bi-arrow-up-circle me-1"></i>Recommend Promotion</button>
                <button class="btn btn-sm btn-outline-primary ms-1" onclick="recommend('award', ${a.employee_id}, '${safeOutput(a.first_name+' '+a.last_name).replace(/'/g,"\\'")}', '${safeOutput(a.cycle_name).replace(/'/g,"\\'")}', ${a.overall_rating})"><i class="bi bi-trophy me-1"></i>Recommend Award</button>
            </div>`;
        }
        $('#appraisalViewBody').html(`
            <div class="d-flex justify-content-between flex-wrap mb-2">
                <div><div class="fs-5 fw-bold">${safeOutput(a.first_name+' '+a.last_name)}</div>
                    <div class="small text-muted">${safeOutput(a.designation_name || '—')} · ${safeOutput(a.cycle_name)}</div></div>
                <div class="text-end">${apStatusBadge(a.status)}<div class="small text-muted mt-1">${safeOutput(a.appraisal_date)}</div>
                    ${a.overall_rating !== null ? `<div class="mt-1">Overall: ${starsInline(Math.round(a.overall_rating))} <strong>${Number(a.overall_rating).toFixed(2)}/5</strong></div>` : ''}</div>
            </div>
            <table class="table table-sm align-middle"><thead><tr><th>Indicator</th><th>Expected</th><th>Actual</th></tr></thead><tbody>${rows}</tbody></table>
            ${a.remarks ? `<div><strong>Remarks:</strong> ${safeOutput(a.remarks)}</div>` : ''}
            ${a.reject_reason ? `<div class="text-danger"><strong>Reject reason:</strong> ${safeOutput(a.reject_reason)}</div>` : ''}
            ${a.approved_by_name ? `<div class="small text-muted mt-1">${a.status==='rejected'?'Rejected':'Approved'} by ${safeOutput(a.approved_by_name)}</div>` : ''}
            ${recommend}`);
        new bootstrap.Modal(document.getElementById('appraisalViewModal')).show();
    });
}
function printScorecard() {
    const html = $('#appraisalViewBody').html();
    const w = window.open('', '_blank');
    w.document.write('<html><head><title>Appraisal Scorecard</title><meta charset="utf-8"><style>body{font-family:sans-serif;padding:20px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:6px;text-align:left}</style></head><body>'+html+'</body></html>');
    w.document.close(); w.focus(); setTimeout(() => { w.print(); }, 250);
}

function apAction(id, action) {
    const labels = { submit:'Submit', approve:'Approve', reject:'Reject' };
    const opts = { title: labels[action]+'?', icon: action==='approve'?'question':'warning', showCancelButton:true, confirmButtonColor: action==='reject'?'#dc3545':'#0d6efd', confirmButtonText:'Yes' };
    if (action === 'reject') { opts.input='text'; opts.inputPlaceholder='Reason (required)'; opts.inputValidator = v => !v ? 'A reason is required' : undefined; }
    Swal.fire(opts).then(res => {
        if (!res.isConfirmed) return;
        $.post('<?= buildUrl('api/change_appraisal_status.php') ?>', { appraisal_id:id, action, reject_reason:res.value||'', _csrf:HP_CSRF }, function (r) {
            if (r.success) { loadAppraisals(); Swal.fire({ icon:'success', title:'Done!', text:r.message, timer:2000, showConfirmButton:false }); }
            else Swal.fire({ icon:'error', title:'Error', text:r.message });
        }, 'json');
    });
}

function recommend(type, empId, empName, cycleName, overall) {
    // D20 — open the shared Tier 1 lifecycle modal pre-filled for this employee.
    const modalEl = document.getElementById('lifecycleModal');
    if (!modalEl) return;
    const m = new bootstrap.Modal(modalEl);
    $(modalEl).one('shown.bs.modal', function () {
        // employee picker: inject + select the option
        const $emp = $('#lc_employee');
        if ($emp.is('select')) { $emp.append(new Option(empName, empId, true, true)).trigger('change'); }
        $('#lc_type').val(type).trigger('change');
        $('#lc_title').val(type === 'promotion' ? 'Promotion recommended after appraisal' : 'Award recommended after appraisal');
        $('#lifecycleForm [name="description"]').val(`Following ${cycleName} — overall ${Number(overall).toFixed(2)}/5.`);
        if (type === 'award') $('#lc_award_type').val('Performance Award');
    });
    m.show();
}

$(function () {
    if (!AP_CAN_CREATE && !AP_CAN_EDIT && true) { /* viewers still see the list */ }

    apTable = $('#appraisalsTable').DataTable({
        responsive:false, scrollX:true, pageLength:25, order:[[0,'desc']], dom:'rtip',
        language:{ emptyTable:'No appraisals yet.', zeroRecords:'No matching records.' },
        drawCallback: function () { apCards(this.api().rows({page:'current'})[0].map(i=>AP_ROWS[i]).filter(Boolean)); }
    });

    // cycle filter + New Appraisal cycle select share one load
    function loadCyclesInto() {
        $.getJSON('<?= buildUrl('api/manage_appraisal_cycles.php') ?>', { action:'list' }, function (res) {
            if (!res.success) return;
            AP_CYCLES = res.data;
            const opts = res.data.map(c => `<option value="${c.cycle_id}">${safeOutput(c.cycle_name)}${c.status==='closed'?' (closed)':''}</option>`).join('');
            $('#af_cycle').html('<option value="">All cycles</option>' + opts);
            $('#ap_cycle').html('<option value="">Select…</option>' + res.data.filter(c=>c.status==='open').map(c=>`<option value="${c.cycle_id}">${safeOutput(c.cycle_name)}</option>`).join(''));
        });
    }
    loadCyclesInto();
    window.__reloadCycles = loadCyclesInto;

    $('#af_employee').select2({ theme:'bootstrap-5', placeholder:'All employees', allowClear:true, width:'100%', minimumInputLength:1,
        ajax:{ url:'<?= buildUrl('api/account/search_employees.php') ?>', dataType:'json', delay:300, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
    $('#af_cycle,#af_status,#af_employee').on('change', loadAppraisals);
    $('#af_reset').on('click', function () { $('#af_cycle,#af_status').val(''); $('#af_employee').val(null).trigger('change.select2'); loadAppraisals(); });

    function apView() { if (window.innerWidth < 768) { $('#apTableView').addClass('d-none'); $('#apCardView').removeClass('d-none'); } else { $('#apTableView').removeClass('d-none'); $('#apCardView').addClass('d-none'); } }
    apView(); $(window).on('resize', apView);
    loadAppraisals();
});

<?php if ($can_create): ?>
window.openAppraisalModal = function () {
    $('#appraisalForm')[0].reset(); $('#ap_items').html('<p class="text-muted">Pick an employee to load the competency indicators.</p>');
    const modal = new bootstrap.Modal(document.getElementById('appraisalModal'));
    modal.show();
};
$('#appraisalModal').on('shown.bs.modal', function () {
    if (!$('#ap_employee').hasClass('select2-hidden-accessible')) {
        $('#ap_employee').select2({ theme:'bootstrap-5', dropdownParent:$('#appraisalModal'), placeholder:'Select employee…', width:'100%', minimumInputLength:1,
            ajax:{ url:'<?= buildUrl('api/account/search_employees.php') ?>', dataType:'json', delay:300, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
    }
});
$('#ap_employee').on('change', function () {
    const empId = $(this).val();
    if (!empId) { $('#ap_items').html('<p class="text-muted">Pick an employee to load the competency indicators.</p>'); return; }
    $('#ap_items').html('<div class="text-center text-muted py-2"><span class="spinner-border spinner-border-sm"></span></div>');
    // resolve the employee's designation, then load indicators+targets for it
    $.getJSON('<?= buildUrl('api/get_employee.php') ?>', { id: empId }, function (er) {
        const desig = (er.success && er.data) ? (er.data.designation_id || 0) : 0;
        $.getJSON('<?= buildUrl('api/get_indicators.php') ?>', { designation_id: desig }, function (res) {
            if (!res.success || !res.indicators.length) { $('#ap_items').html('<p class="text-warning mb-0">No active indicators to rate. Set them up in the Indicators tab first.</p>'); return; }
            let html = '<div class="small text-muted mb-2">Rate each indicator 1–5. Blue dot = the designation\'s target.</div>';
            res.categories.forEach(c => {
                const rows = res.indicators.filter(i => Number(i.category_id) === Number(c.category_id));
                if (!rows.length) return;
                html += `<div class="mb-2"><div class="small text-uppercase text-muted fw-semibold">${safeOutput(c.category_name)}</div>`;
                rows.forEach(i => {
                    const exp = res.targets[i.indicator_id] || 0;
                    html += `<div class="py-1 border-bottom"><div class="d-flex justify-content-between align-items-center">
                        <span>${safeOutput(i.indicator_name)}</span>${apStarInput('rating['+i.indicator_id+']', exp)}</div>
                        <input class="form-control form-control-sm mt-1" name="comment[${i.indicator_id}]" placeholder="Comment (optional)"></div>`;
                });
                html += '</div>';
            });
            $('#ap_items').html(html);
        });
    });
});
function apStarInput(name, expected) {
    let h = `<span class="star-rating" data-name="${name}"><input type="hidden" name="${name}" value="0">`;
    for (let i = 1; i <= 5; i++) { const mark = (expected && i === expected) ? ' expected-mark' : ''; h += `<button type="button" class="star${mark}" data-val="${i}">&#9733;</button>`; }
    h += '<span class="star-clear">clear</span></span>';
    return h;
}
$('#appraisalForm').on('submit', function (e) {
    e.preventDefault();
    const mode = (e.originalEvent && e.originalEvent.submitter) ? e.originalEvent.submitter.value : 'draft';
    const fd = new FormData(this); fd.set('mode', mode);
    const btns = $(this).find('[type="submit"]'); btns.prop('disabled', true);
    $.ajax({ url:'<?= buildUrl('api/add_appraisal.php') ?>', type:'POST', data:fd, contentType:false, processData:false, dataType:'json',
        success: r => { if (r.success) { bootstrap.Modal.getInstance(document.getElementById('appraisalModal')).hide(); loadAppraisals(); Swal.fire({icon:'success',title:'Saved!',text:r.message,timer:1800,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message}); },
        error: () => Swal.fire({icon:'error',title:'Error',text:'Server error.'}),
        complete: () => btns.prop('disabled', false)
    });
});
<?php endif; ?>

<?php if ($can_edit): ?>
window.openCyclesModal = function () { loadCyclesList(); new bootstrap.Modal(document.getElementById('cyclesModal')).show(); };
function loadCyclesList() {
    $.getJSON('<?= buildUrl('api/manage_appraisal_cycles.php') ?>', { action:'list' }, function (res) {
        if (!res.success) return;
        if (!res.data.length) { $('#cyclesList').html('<p class="text-muted mb-0">No cycles yet.</p>'); return; }
        let html = '<table class="table table-sm align-middle"><thead><tr><th>Name</th><th>Period</th><th>Appraisals</th><th>Status</th><th></th></tr></thead><tbody>';
        res.data.forEach(c => {
            html += `<tr><td>${safeOutput(c.cycle_name)}</td><td class="small">${safeOutput(c.period_from)} → ${safeOutput(c.period_to)}</td>
                <td>${c.appraisal_count}</td><td>${c.status==='open'?'<span class="badge bg-success">Open</span>':'<span class="badge bg-secondary">Closed</span>'}</td>
                <td class="text-end"><div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary" onclick="editCycle(${c.cycle_id})" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-outline-${c.status==='open'?'warning':'success'}" onclick="toggleCycle(${c.cycle_id},'${c.status==='open'?'close':'reopen'}')" title="${c.status==='open'?'Close':'Reopen'}"><i class="bi bi-${c.status==='open'?'lock':'unlock'}"></i></button>
                    <button class="btn btn-outline-danger" onclick="deleteCycle(${c.cycle_id})" title="Delete"><i class="bi bi-trash"></i></button>
                </div></td></tr>`;
        });
        $('#cyclesList').html(html + '</tbody></table>');
    });
}
window.editCycle = function (id) { const c = AP_CYCLES.find(x=>Number(x.cycle_id)===id); if (!c) return; $('#cy_id').val(id); $('#cy_name').val(c.cycle_name); $('#cy_from').val(c.period_from); $('#cy_to').val(c.period_to); };
window.toggleCycle = function (id, action) { $.post('<?= buildUrl('api/manage_appraisal_cycles.php') ?>', { action, cycle_id:id, _csrf:HP_CSRF }, cycleDone, 'json'); };
window.deleteCycle = function (id) { Swal.fire({title:'Delete cycle?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Delete'}).then(r=>{ if(r.isConfirmed) $.post('<?= buildUrl('api/manage_appraisal_cycles.php') ?>',{action:'delete',cycle_id:id,_csrf:HP_CSRF},cycleDone,'json'); }); };
function cycleDone(r) { if (r.success) { loadCyclesList(); if (window.__reloadCycles) window.__reloadCycles(); } else Swal.fire({icon:'error',title:'Error',text:r.message}); }
$('#cycleForm').on('submit', function (e) {
    e.preventDefault();
    const id = $('#cy_id').val();
    const data = { action: id ? 'update' : 'add', cycle_id:id, cycle_name:$('#cy_name').val(), period_from:$('#cy_from').val(), period_to:$('#cy_to').val(), _csrf:HP_CSRF };
    $.post('<?= buildUrl('api/manage_appraisal_cycles.php') ?>', data, function (r) { if (r.success) { this && 0; $('#cycleForm')[0].reset(); $('#cy_id').val(''); cycleDone(r); } else Swal.fire({icon:'error',title:'Error',text:r.message}); }, 'json');
});
<?php endif; ?>
</script>

<?php includeFooter(); ?>
