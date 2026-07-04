<?php
// Training & Development — Tier 3, Phase 3.5. page_key: trainings.
// Trainings + participants; certificates register into the central library
// (D22) so the existing expiry cron alerts on expiring certifications. Cost is
// informational only (D21) — no ledger posting.
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('trainings');

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View trainings', 'User viewed the Training page');

$can_create = canCreate('trainings');
$can_edit   = canEdit('trainings');
$can_delete = canDelete('trainings');

$training_types = $pdo->query("SELECT training_type_id, type_name FROM training_types WHERE status='active' ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-mortarboard me-2 text-primary"></i>Training &amp; Development</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" onclick="openTrainingModal()"><i class="bi bi-plus-circle me-1"></i> New Training</button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><div class="fs-4 fw-bold text-primary" id="ts_planned">0</div><div class="small text-muted">Planned</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#fff3cd;border:1px solid #ffe69c"><div class="fs-4 fw-bold text-warning" id="ts_progress">0</div><div class="small text-muted">In progress</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#d1e7dd;border:1px solid #a3cfbb"><div class="fs-4 fw-bold text-success" id="ts_completed">0</div><div class="small text-muted">Completed this year</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#e9ecef;border:1px solid #dee2e6"><div class="fs-4 fw-bold text-secondary" id="ts_participants">0</div><div class="small text-muted">Trained this year</div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Type</label>
                <select class="form-select form-select-sm" id="tf_type"><option value="">All types</option>
                    <?php foreach ($training_types as $tt): ?><option value="<?= (int)$tt['training_type_id'] ?>"><?= safe_output($tt['type_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select class="form-select form-select-sm" id="tf_status"><option value="">All statuses</option>
                    <option value="planned">Planned</option><option value="in_progress">In progress</option>
                    <option value="completed">Completed</option><option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" id="tf_from"></div>
            <div class="col-6 col-md-2"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" id="tf_to"></div>
            <div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-secondary w-100" id="tf_reset"><i class="bi bi-arrow-clockwise"></i></button></div>
        </div>
    </div></div>

    <div id="trTableView" class="card border-0 shadow-sm"><div class="card-body">
        <table id="trainingsTable" class="table table-hover align-middle w-100">
            <thead style="--bs-table-color:#fff;--bs-table-bg:#0d6efd;"><tr><th class="text-center">S/NO</th><th>Title</th><th>Type</th><th>Dates</th><th>Participants</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody></tbody>
        </table>
    </div></div>
    <div id="trCardView" class="row g-2 d-none"></div>
</div>

<!-- Training detail / participants modal -->
<div class="modal fade" id="trainingViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-mortarboard me-1"></i> Training Details</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="trainingViewBody"></div>
    </div></div>
</div>

<?php if ($can_create): ?>
<!-- New/Edit Training modal -->
<div class="modal fade" id="trainingModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header bg-primary text-white">
            <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> <span id="trainingModalTitle">New Training</span></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <form id="trainingForm">
            <div class="modal-body">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="training_id" id="tr_id">
                <div class="row">
                    <div class="col-md-8 mb-3"><label class="form-label">Title <span class="text-danger">*</span></label><input class="form-control" name="title" id="tr_title" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="training_type_id" id="tr_type" required>
                            <?php foreach ($training_types as $tt): ?><option value="<?= (int)$tt['training_type_id'] ?>"><?= safe_output($tt['type_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Trainer</label>
                        <select class="form-select" name="trainer_kind" id="tr_trainer_kind"><option value="internal">Internal (employee)</option><option value="external">External</option></select>
                    </div>
                    <div class="col-md-8 mb-3">
                        <div id="tr_internal_wrap"><label class="form-label">Internal Trainer</label><select class="form-select" name="trainer_employee_id" id="tr_trainer_emp"></select></div>
                        <div id="tr_external_wrap" class="d-none"><label class="form-label">External Trainer <span class="text-danger">*</span></label><input class="form-control" name="trainer_name" id="tr_trainer_name"></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Venue</label><input class="form-control" name="venue" id="tr_venue"></div>
                    <div class="col-md-4 mb-3"><label class="form-label">Start <span class="text-danger">*</span></label><input type="date" class="form-control" name="start_date" id="tr_start" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">End</label><input type="date" class="form-control" name="end_date" id="tr_end"></div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Cost</label><input type="number" min="0" step="0.01" class="form-control" name="cost" id="tr_cost">
                        <div class="form-text">Informational only — record actual payment through Expenses as usual.</div></div>
                    <div class="col-md-8 mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" id="tr_desc" rows="2"></textarea></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<script>
// HTML-escape helper (page-local per app convention)
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }
const TR_CSRF = <?= json_encode(csrf_token()) ?>;
const TR_CAN_CREATE = <?= json_encode($can_create) ?>;
const TR_CAN_EDIT   = <?= json_encode($can_edit) ?>;
const TR_CAN_DELETE = <?= json_encode($can_delete) ?>;
let trTable = null, TR_ROWS = [], CUR_TRAINING = null;

function trStatusBadge(s) {
    const map = { planned:['#0d6efd','#fff'], in_progress:['#fff3cd','#664d03'], completed:['#198754','#fff'], cancelled:['#6c757d','#fff'] };
    const [bg, fg] = map[s] || ['#e9ecef','#495057'];
    return `<span class="badge" style="background:${bg};color:${fg}">${s.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase())}</span>`;
}
function partBadge(s) {
    const map = { enrolled:'secondary', attended:'info', completed:'success', failed:'danger', withdrawn:'dark' };
    return `<span class="badge bg-${map[s]||'secondary'}">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`;
}
function certChip(p) {
    if (!p.certificate_path) return '';
    let chip = '';
    if (p.certificate_expire_date) {
        const d = Number(p.cert_days_left);
        if (d < 0) chip = ' <span class="badge bg-danger">Expired</span>';
        else if (d <= 30) chip = ` <span class="badge bg-warning text-dark">${d}d</span>`;
    }
    return `<a href="<?= buildUrl('api/download_training_certificate.php') ?>?participant_id=${p.participant_id}" target="_blank" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-download"></i></a>${chip}`;
}
function trActions(r) {
    let items = `<li><button class="dropdown-item py-2" onclick="viewTraining(${r.training_id})"><i class="bi bi-eye text-primary me-2"></i>View / Participants</button></li>`;
    if (TR_CAN_EDIT && r.status === 'planned') items += `<li><button class="dropdown-item py-2" onclick="trStatus(${r.training_id},'in_progress')"><i class="bi bi-play-circle text-primary me-2"></i>Start</button></li>`;
    if (TR_CAN_EDIT && r.status === 'in_progress') items += `<li><button class="dropdown-item py-2" onclick="trStatus(${r.training_id},'completed')"><i class="bi bi-check2-all text-success me-2"></i>Complete</button></li>`;
    if (TR_CAN_EDIT && (r.status==='planned'||r.status==='in_progress')) items += `<li><button class="dropdown-item py-2 text-danger" onclick="trStatus(${r.training_id},'cancelled')"><i class="bi bi-x-circle text-danger me-2"></i>Cancel</button></li>`;
    if (TR_CAN_DELETE) items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 text-danger" onclick="trDelete(${r.training_id})"><i class="bi bi-trash text-danger me-2"></i>Delete</button></li>`;
    return `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle px-2" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
}
function loadTrainings() {
    const p = { training_type_id: $('#tf_type').val() || '', status: $('#tf_status').val(), date_from: $('#tf_from').val(), date_to: $('#tf_to').val() };
    $.getJSON('<?= buildUrl('api/get_trainings.php') ?>', p, function (res) {
        if (!res.success) { Swal.fire({icon:'error',title:'Error',text:res.message||'Could not load.'}); return; }
        TR_ROWS = res.data;
        $('#ts_planned').text(res.stats.planned); $('#ts_progress').text(res.stats.in_progress);
        $('#ts_completed').text(res.stats.completed_year); $('#ts_participants').text(res.stats.participants_year);
        const data = res.data.map(r => [
            '', safeOutput(r.title), safeOutput(r.type_name || '—'),
            safeOutput(r.start_date) + (r.end_date ? ' → ' + safeOutput(r.end_date) : ''),
            r.participant_count, trStatusBadge(r.status), trActions(r)
        ]);
        trTable.clear().rows.add(data).draw();
    });
}
function trCards(rows) {
    if (!rows.length) { $('#trCardView').html('<div class="col-12 text-center py-5 text-muted">No trainings yet.</div>'); return; }
    let html = '';
    rows.forEach(r => {
        html += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
            <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(r.title)}</div>${trStatusBadge(r.status)}</div>
            <div class="small text-muted mt-1">${safeOutput(r.type_name||'—')} · ${safeOutput(r.start_date)} · ${r.participant_count} participant(s)</div>
            <button class="btn btn-sm btn-outline-primary mt-2" onclick="viewTraining(${r.training_id})"><i class="bi bi-eye"></i> View</button>
        </div></div></div>`;
    });
    $('#trCardView').html(html);
}

function viewTraining(id) {
    $.getJSON('<?= buildUrl('api/get_trainings.php') ?>', { training_id: id }, function (res) {
        if (!res.success) { Swal.fire({icon:'error',title:'Error',text:res.message}); return; }
        CUR_TRAINING = res.data;
        const t = res.data;
        const trainer = t.trainer_kind === 'internal' ? (t.trainer_first ? safeOutput(t.trainer_first+' '+t.trainer_last) : '—') : safeOutput(t.trainer_name || '—');
        let prows = res.participants.map(p => `<tr>
            <td>${safeOutput(p.first_name+' '+p.last_name)}</td>
            <td>${partBadge(p.status)}</td>
            <td>${safeOutput(p.score||'—')}</td>
            <td>${certChip(p)}</td>
            ${TR_CAN_EDIT ? `<td class="text-end"><button class="btn btn-sm btn-outline-secondary py-0" onclick="editParticipant(${p.participant_id})"><i class="bi bi-pencil"></i></button></td>` : '<td></td>'}
        </tr>`).join('');
        if (!res.participants.length) prows = `<tr><td colspan="5" class="text-muted text-center">No participants yet.</td></tr>`;
        $('#trainingViewBody').html(`
            <div class="d-flex justify-content-between flex-wrap mb-2">
                <div><div class="fs-5 fw-bold">${safeOutput(t.title)}</div>
                    <div class="small text-muted">${safeOutput(t.type_name||'—')} · Trainer: ${trainer}</div></div>
                <div class="text-end">${trStatusBadge(t.status)}<div class="small text-muted mt-1">${safeOutput(t.start_date)}${t.end_date?' → '+safeOutput(t.end_date):''}</div></div>
            </div>
            ${t.venue ? `<div class="small"><strong>Venue:</strong> ${safeOutput(t.venue)}</div>` : ''}
            ${t.cost ? `<div class="small"><strong>Cost:</strong> ${Number(t.cost).toLocaleString()} <span class="text-muted">(informational)</span></div>` : ''}
            ${t.description ? `<div class="small mb-2">${safeOutput(t.description)}</div>` : ''}
            <div class="d-flex justify-content-between align-items-center mt-3 mb-1">
                <strong>Participants</strong>
                ${TR_CAN_EDIT ? `<button class="btn btn-sm btn-primary" onclick="openAddParticipants(${t.training_id})"><i class="bi bi-person-plus"></i> Add</button>` : ''}
            </div>
            <table class="table table-sm align-middle"><thead><tr><th>Employee</th><th>Status</th><th>Score</th><th>Certificate</th><th></th></tr></thead><tbody>${prows}</tbody></table>`);
        new bootstrap.Modal(document.getElementById('trainingViewModal')).show();
    });
}
window.viewTraining = viewTraining;

function trStatus(id, status) {
    const labels = { in_progress:'Start', completed:'Complete', cancelled:'Cancel' };
    Swal.fire({ title: labels[status]+' training?', icon: status==='cancelled'?'warning':'question', showCancelButton:true, confirmButtonColor: status==='cancelled'?'#dc3545':'#0d6efd', confirmButtonText:'Yes' })
        .then(r => { if (!r.isConfirmed) return;
            $.post('<?= buildUrl('api/manage_trainings.php') ?>', { action:'change_status', training_id:id, status, _csrf:TR_CSRF }, function (res) {
                if (res.success) { loadTrainings(); Swal.fire({icon:'success',title:'Done!',text:res.message,timer:1600,showConfirmButton:false}); }
                else Swal.fire({icon:'error',title:'Error',text:res.message});
            }, 'json'); });
}
window.trStatus = trStatus;
window.trDelete = function (id) {
    Swal.fire({ title:'Delete training?', icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Delete' })
        .then(r => { if (r.isConfirmed) $.post('<?= buildUrl('api/manage_trainings.php') ?>', { action:'delete', training_id:id, _csrf:TR_CSRF }, function (res) { if (res.success) { loadTrainings(); } else Swal.fire({icon:'error',title:'Error',text:res.message}); }, 'json'); });
};

<?php if ($can_edit): ?>
window.openAddParticipants = function (trainingId) {
    Swal.fire({
        title: 'Add participants', html: '<select id="swalEmp" multiple style="width:100%"></select>',
        showCancelButton: true, confirmButtonText: 'Add',
        didOpen: () => {
            $('#swalEmp').select2({ dropdownParent: $('.swal2-popup'), placeholder: 'Search employees…', width: '100%', minimumInputLength: 1,
                ajax: { url: '<?= buildUrl('api/account/search_employees.php') ?>', dataType:'json', delay:300, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
        },
        preConfirm: () => $('#swalEmp').val()
    }).then(res => {
        if (!res.isConfirmed || !res.value || !res.value.length) return;
        $.post('<?= buildUrl('api/manage_training_participants.php') ?>', { action:'add', training_id:trainingId, 'employee_ids[]':res.value, _csrf:TR_CSRF }, function (r) {
            if (r.success) { viewTraining(trainingId); loadTrainings(); } else Swal.fire({icon:'error',title:'Error',text:r.message});
        }, 'json');
    });
};
window.editParticipant = function (pid) {
    const p = (CUR_TRAINING && window.__lastParts) ? null : null;
    // fetch fresh from the currently open training
    const training = CUR_TRAINING;
    Swal.fire({
        title: 'Update participant',
        html: `
            <select id="pStatus" class="form-select mb-2">
                <option value="enrolled">Enrolled</option><option value="attended">Attended</option>
                <option value="completed">Completed</option><option value="failed">Failed</option><option value="withdrawn">Withdrawn</option>
            </select>
            <input id="pScore" class="form-control mb-2" placeholder="Score (e.g. 87%, Pass)">
            <input id="pRemarks" class="form-control mb-2" placeholder="Remarks">
            <div class="text-start small text-muted mb-1">Certificate (optional):</div>
            <input id="pCert" type="file" class="form-control mb-2">
            <input id="pCertExp" type="date" class="form-control" title="Certificate expiry (optional)">`,
        showCancelButton: true, confirmButtonText: 'Save',
        preConfirm: () => ({ status: $('#pStatus').val(), score: $('#pScore').val(), remarks: $('#pRemarks').val(), file: document.getElementById('pCert').files[0], exp: $('#pCertExp').val() })
    }).then(res => {
        if (!res.isConfirmed) return;
        const v = res.value;
        $.post('<?= buildUrl('api/manage_training_participants.php') ?>', { action:'update', participant_id:pid, status:v.status, score:v.score, remarks:v.remarks, _csrf:TR_CSRF }, function (r) {
            if (!r.success) { Swal.fire({icon:'error',title:'Error',text:r.message}); return; }
            if (v.file) {
                const fd = new FormData(); fd.append('participant_id', pid); fd.append('certificate_expire_date', v.exp||''); fd.append('file', v.file); fd.append('_csrf', TR_CSRF);
                $.ajax({ url:'<?= buildUrl('api/upload_training_certificate.php') ?>', type:'POST', data:fd, contentType:false, processData:false, dataType:'json',
                    success: rr => { if (training) viewTraining(training.training_id); if (!rr.success) Swal.fire({icon:'error',title:'Certificate',text:rr.message}); },
                    error: () => Swal.fire({icon:'error',title:'Error',text:'Certificate upload failed.'}) });
            } else if (training) { viewTraining(training.training_id); }
        }, 'json');
    });
};
<?php endif; ?>

$(function () {
    trTable = $('#trainingsTable').DataTable({
        responsive:false, scrollX:true, pageLength:25, order:[[3,'desc']], dom:'rtip',
        columnDefs: [{ targets: 0, orderable: false, searchable: false, className: 'text-center',
            render: (d, t, row, meta) => meta.row + 1 + meta.settings._iDisplayStart }],
        language:{ emptyTable:'No trainings yet.', zeroRecords:'No matching records.' },
        drawCallback: function () { trCards(this.api().rows({page:'current'})[0].map(i=>TR_ROWS[i]).filter(Boolean)); }
    });
    $('#tf_type,#tf_status').on('change', loadTrainings);
    $('#tf_from,#tf_to').on('change', loadTrainings);
    $('#tf_reset').on('click', function () { $('#tf_type,#tf_status,#tf_from,#tf_to').val(''); loadTrainings(); });
    function trView() { if (window.innerWidth < 768) { $('#trTableView').addClass('d-none'); $('#trCardView').removeClass('d-none'); } else { $('#trTableView').removeClass('d-none'); $('#trCardView').addClass('d-none'); } }
    trView(); $(window).on('resize', trView);
    loadTrainings();
});

<?php if ($can_create): ?>
window.openTrainingModal = function () {
    $('#trainingForm')[0].reset(); $('#tr_id').val(''); $('#trainingModalTitle').text('New Training');
    $('#tr_trainer_kind').val('internal').trigger('change');
    new bootstrap.Modal(document.getElementById('trainingModal')).show();
};
$('#tr_trainer_kind').on('change', function () {
    if ($(this).val() === 'external') { $('#tr_internal_wrap').addClass('d-none'); $('#tr_external_wrap').removeClass('d-none'); }
    else { $('#tr_external_wrap').addClass('d-none'); $('#tr_internal_wrap').removeClass('d-none'); }
});
$('#trainingModal').on('shown.bs.modal', function () {
    if (!$('#tr_trainer_emp').hasClass('select2-hidden-accessible')) {
        $('#tr_trainer_emp').select2({ theme:'bootstrap-5', dropdownParent:$('#trainingModal'), placeholder:'Search…', allowClear:true, width:'100%', minimumInputLength:1,
            ajax:{ url:'<?= buildUrl('api/account/search_employees.php') ?>', dataType:'json', delay:300, data:p=>({q:p.term}), processResults:d=>({results:d.results}), cache:true } });
    }
});
$('#trainingForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'); const orig = btn.html(); btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
    const fd = new FormData(this); fd.set('action', $('#tr_id').val() ? 'update' : 'add');
    $.ajax({ url:'<?= buildUrl('api/manage_trainings.php') ?>', type:'POST', data:fd, contentType:false, processData:false, dataType:'json',
        success: r => { if (r.success) { bootstrap.Modal.getInstance(document.getElementById('trainingModal')).hide(); loadTrainings(); Swal.fire({icon:'success',title:'Saved!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message}); },
        error: () => Swal.fire({icon:'error',title:'Error',text:'Server error.'}),
        complete: () => btn.prop('disabled', false).html(orig) });
});
<?php endif; ?>
</script>

<?php includeFooter(); ?>
