<?php
// Recruitment — Tier 4, Phase 4.5 (D27 internal ATS, D28a hire loop).
// page_key: recruitment. Two tabs: Openings + Candidates pipeline.
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('recruitment');
includeHeader();
require_once __DIR__ . '/includes/star_rating.php';

logActivity($pdo, $_SESSION['user_id'], 'View recruitment', 'User viewed the Recruitment page');

$can_create = canCreate('recruitment');
$can_edit   = canEdit('recruitment');
$designations = $pdo->query("SELECT designation_id, designation_name FROM designations WHERE status='active' ORDER BY designation_name")->fetchAll(PDO::FETCH_ASSOC);
$departments  = $pdo->query("SELECT department_id, department_name FROM departments WHERE status='active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<?php starRatingAssets(); ?>

<div class="container-fluid mt-4">
    <h4 class="mb-3"><i class="bi bi-person-badge me-2 text-primary"></i>Recruitment</h4>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><div class="fs-4 fw-bold text-primary" id="rs_open">0</div><div class="small text-muted">Open positions</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#e9ecef;border:1px solid #dee2e6"><div class="fs-4 fw-bold text-secondary" id="rs_cands">0</div><div class="small text-muted">Total candidates</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#fff3cd;border:1px solid #ffe69c"><div class="fs-4 fw-bold text-warning" id="rs_interview">0</div><div class="small text-muted">In interview</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#d1e7dd;border:1px solid #a3cfbb"><div class="fs-4 fw-bold text-success" id="rs_hired">0</div><div class="small text-muted">Hired this year</div></div></div>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-openings" type="button">Openings</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-candidates" type="button">Candidates</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="pane-openings" role="tabpanel">
            <?php if ($can_create): ?><div class="d-flex justify-content-end mb-3"><button class="btn btn-sm btn-primary" onclick="openOpeningModal()"><i class="bi bi-plus-circle me-1"></i> New Opening</button></div><?php endif; ?>
            <div id="openingsList" class="row g-3"><div class="col-12 text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span></div></div>
        </div>
        <div class="tab-pane fade" id="pane-candidates" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div class="d-flex gap-2">
                    <select class="form-select form-select-sm" id="cand_opening" style="min-width:180px"><option value="">All openings</option></select>
                    <select class="form-select form-select-sm" id="cand_stage" style="min-width:150px"><option value="">All stages</option>
                        <option value="applied">Applied</option><option value="shortlisted">Shortlisted</option><option value="interview">Interview</option>
                        <option value="offered">Offered</option><option value="hired">Hired</option><option value="rejected">Rejected</option></select>
                </div>
                <?php if ($can_create): ?><button class="btn btn-sm btn-primary" onclick="openCandidateModal()"><i class="bi bi-person-plus me-1"></i> Add Candidate</button><?php endif; ?>
            </div>
            <div id="candidatesList" class="row g-3"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="candidateViewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-person me-1"></i> Candidate</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="candidateViewBody"></div>
</div></div></div>

<?php if ($can_create): ?>
<div class="modal fade" id="openingModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="openingModalTitle">New Opening</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="openingForm"><div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="opening_id" id="op_id">
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Job Title <span class="text-danger">*</span></label><input class="form-control" name="job_title" id="op_title" required></div>
            <div class="col-md-3 mb-3"><label class="form-label">Openings</label><input type="number" min="1" class="form-control" name="openings_count" id="op_count" value="1"></div>
            <div class="col-md-3 mb-3"><label class="form-label">Close Date</label><input type="date" class="form-control" name="close_date" id="op_close"></div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Designation</label><select class="form-select" name="designation_id" id="op_desig"><option value="">—</option><?php foreach ($designations as $d): ?><option value="<?= (int)$d['designation_id'] ?>"><?= safe_output($d['designation_name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Department</label><select class="form-select" name="department_id" id="op_dept"><option value="">—</option><?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= safe_output($d['department_name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" id="op_desc" rows="2"></textarea></div>
        <div class="mb-3"><label class="form-label">Requirements</label><textarea class="form-control" name="requirements" id="op_req" rows="2"></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div></form>
</div></div></div>

<div class="modal fade" id="candidateModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title">Add Candidate</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="candidateForm"><div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="add">
        <div class="mb-3"><label class="form-label">Opening <span class="text-danger">*</span></label><select class="form-select" name="opening_id" id="cn_opening" required></select></div>
        <div class="mb-3"><label class="form-label">Full Name <span class="text-danger">*</span></label><input class="form-control" name="full_name" required></div>
        <div class="row"><div class="col-6 mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email"></div><div class="col-6 mb-3"><label class="form-label">Phone</label><input class="form-control" name="phone"></div></div>
        <div class="mb-3"><label class="form-label">Source</label><input class="form-control" name="source" placeholder="Referral / advert / agency…"></div>
        <div class="mb-3"><label class="form-label">CV</label><input type="file" class="form-control" name="cv"><div class="form-text">PDF or Word. Max 10MB.</div></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div></form>
</div></div></div>
<?php endif; ?>

<script>
// HTML-escape helper (page-local per app convention)
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }
const RC_CSRF = <?= json_encode(csrf_token()) ?>;
const RC_CAN_CREATE=<?= json_encode($can_create) ?>, RC_CAN_EDIT=<?= json_encode($can_edit) ?>;
const STAGES = ['applied','shortlisted','interview','offered','hired'];
let OPENINGS = [];

function opStatusBadge(s){ const m={open:'success',on_hold:'warning',closed:'secondary'}; return `<span class="badge bg-${m[s]||'secondary'}">${s.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase())}</span>`; }
function stageBadge(s){ const m={applied:'#6c757d',shortlisted:'#0dcaf0',interview:'#0d6efd',offered:'#fd7e14',hired:'#198754',rejected:'#dc3545'}; return `<span class="badge" style="background:${m[s]||'#6c757d'};color:#fff">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`; }

function loadOpenings(){
    $.getJSON('<?= buildUrl('api/get_openings.php') ?>', {}, function(res){
        if (!res.success) return;
        OPENINGS=res.data;
        $('#rs_open').text(res.stats.open_positions); $('#rs_cands').text(res.stats.total_candidates); $('#rs_interview').text(res.stats.in_interview); $('#rs_hired').text(res.stats.hired_year);
        $('#cand_opening').html('<option value="">All openings</option>'+res.data.map(o=>`<option value="${o.opening_id}">${safeOutput(o.job_title)}</option>`).join(''));
        $('#cn_opening').html(res.data.filter(o=>o.status==='open').map(o=>`<option value="${o.opening_id}">${safeOutput(o.job_title)}</option>`).join(''));
        $('#openingsList').html(res.data.map(o=>`<div class="col-md-6 col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(o.job_title)}</div>${opStatusBadge(o.status)}</div>
            <div class="small text-muted mb-2">${safeOutput(o.designation_name||'—')} · ${o.candidate_count} candidate(s) · ${o.hired_count}/${o.openings_count} hired</div>
            ${RC_CAN_EDIT?`<div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="editOpening(${o.opening_id})"><i class="bi bi-pencil"></i></button>
                ${o.status==='open'?`<button class="btn btn-outline-warning" onclick="opStatus(${o.opening_id},'on_hold')">Hold</button><button class="btn btn-outline-secondary" onclick="opStatus(${o.opening_id},'closed')">Close</button>`:`<button class="btn btn-outline-success" onclick="opStatus(${o.opening_id},'open')">Reopen</button>`}
            </div>`:''}</div></div></div>`).join('') || '<div class="col-12 text-center text-muted py-4">No openings yet.</div>');
    });
}
function loadCandidates(){
    $.getJSON('<?= buildUrl('api/get_candidates.php') ?>', { opening_id:$('#cand_opening').val()||'', stage:$('#cand_stage').val() }, function(res){
        if (!res.success) return;
        if (!res.data.length){ $('#candidatesList').html('<div class="col-12 text-center text-muted py-4">No candidates.</div>'); return; }
        $('#candidatesList').html(res.data.map(c=>`<div class="col-md-6 col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(c.full_name)}</div>${stageBadge(c.stage)}</div>
            <div class="small text-muted mb-2">${safeOutput(c.job_title)}${c.email?' · '+safeOutput(c.email):''}</div>
            <button class="btn btn-sm btn-outline-primary" onclick="viewCandidate(${c.candidate_id})"><i class="bi bi-eye"></i> Open</button>
            ${c.cv_path?`<a class="btn btn-sm btn-outline-secondary" href="<?= buildUrl('api/download_candidate_cv.php') ?>?candidate_id=${c.candidate_id}" target="_blank"><i class="bi bi-file-earmark-text"></i></a>`:''}
        </div></div></div>`).join(''));
    });
}
function viewCandidate(id){
    $.getJSON('<?= buildUrl('api/get_candidates.php') ?>', { candidate_id:id }, function(res){
        if (!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message}); return; }
        const c=res.data;
        const curIdx = STAGES.indexOf(c.stage);
        let moveBtns='';
        if (RC_CAN_EDIT && !['hired','rejected'].includes(c.stage)) {
            if (curIdx>=0 && curIdx<STAGES.length-1) moveBtns += `<button class="btn btn-sm btn-primary" onclick="moveStage(${c.candidate_id},'${STAGES[curIdx+1]}')"><i class="bi bi-arrow-right"></i> ${STAGES[curIdx+1].charAt(0).toUpperCase()+STAGES[curIdx+1].slice(1)}</button> `;
            moveBtns += `<button class="btn btn-sm btn-outline-danger" onclick="moveStage(${c.candidate_id},'rejected')">Reject</button>`;
        }
        if (c.stage==='hired' && !c.hired_employee_id && RC_CAN_EDIT) moveBtns += ` <button class="btn btn-sm btn-success" onclick="linkEmployee(${c.candidate_id})"><i class="bi bi-person-check"></i> Link Employee</button>`;
        let ivRows = res.interviews.map(i=>`<tr><td>${safeOutput(i.interview_date)}${i.interview_time?' '+safeOutput(i.interview_time.substring(0,5)):''}</td><td>${safeOutput(i.interviewers||'—')}</td><td>${i.rating?starsInline(i.rating):'<span class="text-muted">—</span>'}</td><td>${safeOutput(i.feedback||'')} <span class="badge bg-light text-dark">${i.status}</span>${RC_CAN_EDIT&&i.status==='scheduled'?` <button class="btn btn-sm btn-link p-0" onclick="recordInterview(${i.interview_id})">Record</button>`:''}</td></tr>`).join('');
        if (!res.interviews.length) ivRows='<tr><td colspan="4" class="text-muted text-center">No interviews.</td></tr>';
        $('#candidateViewBody').html(`
            <div class="d-flex justify-content-between mb-2"><div><div class="fs-5 fw-bold">${safeOutput(c.full_name)}</div><div class="small text-muted">${safeOutput(c.job_title)}</div></div>${stageBadge(c.stage)}</div>
            <div class="small mb-2">${c.email?'<i class="bi bi-envelope me-1"></i>'+safeOutput(c.email)+' ':''}${c.phone?'<i class="bi bi-telephone me-1"></i>'+safeOutput(c.phone):''}</div>
            ${c.stage_notes?`<div class="small text-muted mb-2">Last note: ${safeOutput(c.stage_notes)}</div>`:''}
            ${c.hired_employee_id?`<div class="alert alert-success py-1 small">Hired → employee #${c.hired_employee_id} (onboarding checklist auto-created)</div>`:''}
            <div class="mb-2">${moveBtns}</div>
            <div class="d-flex justify-content-between align-items-center mt-3 mb-1"><strong>Interviews</strong>${RC_CAN_EDIT?`<button class="btn btn-sm btn-outline-primary" onclick="scheduleInterview(${c.candidate_id})"><i class="bi bi-calendar-plus"></i> Schedule</button>`:''}</div>
            <table class="table table-sm align-middle"><thead><tr><th>When</th><th>Interviewers</th><th>Rating</th><th>Feedback</th></tr></thead><tbody>${ivRows}</tbody></table>`);
        new bootstrap.Modal(document.getElementById('candidateViewModal')).show();
    });
}
window.viewCandidate=viewCandidate;
function starsInline(v){ v=parseInt(v,10)||0; let h=''; for(let i=1;i<=5;i++) h+=`<span style="color:${i<=v?'#f5b301':'#ced4da'}">&#9733;</span>`; return h; }

function moveStage(id, stage){
    Swal.fire({ title:'Move to '+stage.charAt(0).toUpperCase()+stage.slice(1)+'?', input:'text', inputPlaceholder:'Note (required)', inputValidator:v=>!v?'A note is required':undefined, showCancelButton:true, confirmButtonText:'Move' }).then(res=>{
        if (!res.isConfirmed) return;
        $.post('<?= buildUrl('api/change_candidate_stage.php') ?>', { candidate_id:id, stage, note:res.value||'', action:'stage', _csrf:RC_CSRF }, function(r){
            if (r.success){ bootstrap.Modal.getInstance(document.getElementById('candidateViewModal'))?.hide(); loadCandidates(); loadOpenings(); Swal.fire({icon:'success',title:'Done!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message});
        }, 'json');
    });
}
window.moveStage=moveStage;
window.linkEmployee=function(id){
    Swal.fire({ title:'Link to employee', html:'<select id="swalEmp" style="width:100%"></select>', showCancelButton:true, confirmButtonText:'Link',
        didOpen:()=>{ $('#swalEmp').select2({ dropdownParent:$('.swal2-popup'), placeholder:'Search employees…', width:'100%', minimumInputLength:1, ajax:{url:'<?= buildUrl('api/account/search_employees.php') ?>',dataType:'json',delay:300,data:p=>({q:p.term}),processResults:d=>({results:d.results}),cache:true} }); },
        preConfirm:()=>$('#swalEmp').val()
    }).then(res=>{
        if (!res.isConfirmed || !res.value) return;
        $.post('<?= buildUrl('api/change_candidate_stage.php') ?>', { candidate_id:id, action:'link_employee', employee_id:res.value, _csrf:RC_CSRF }, function(r){ if (r.success){ viewCandidate(id); loadCandidates(); } else Swal.fire({icon:'error',title:'Error',text:r.message}); }, 'json');
    });
};
window.scheduleInterview=function(cid){
    Swal.fire({ title:'Schedule interview', html:`<input id="ivDate" type="date" class="form-control mb-2"><input id="ivTime" type="time" class="form-control mb-2"><input id="ivWho" class="form-control" placeholder="Interviewers">`, showCancelButton:true, confirmButtonText:'Schedule',
        preConfirm:()=>({date:$('#ivDate').val(),time:$('#ivTime').val(),who:$('#ivWho').val()})
    }).then(res=>{
        if (!res.isConfirmed || !res.value.date) return;
        $.post('<?= buildUrl('api/manage_interview.php') ?>', { action:'schedule', candidate_id:cid, interview_date:res.value.date, interview_time:res.value.time, interviewers:res.value.who, _csrf:RC_CSRF }, function(r){ if (r.success){ viewCandidate(cid); } else Swal.fire({icon:'error',title:'Error',text:r.message}); }, 'json');
    });
};
window.recordInterview=function(iid){
    Swal.fire({ title:'Record interview', html:`<select id="ivRating" class="form-select mb-2"><option value="">No rating</option><option>1</option><option>2</option><option>3</option><option>4</option><option>5</option></select><textarea id="ivFb" class="form-control" placeholder="Feedback"></textarea>`, showCancelButton:true, confirmButtonText:'Save',
        preConfirm:()=>({rating:$('#ivRating').val(),fb:$('#ivFb').val()})
    }).then(res=>{
        if (!res.isConfirmed) return;
        $.post('<?= buildUrl('api/manage_interview.php') ?>', { action:'record', interview_id:iid, rating:res.value.rating, feedback:res.value.fb, _csrf:RC_CSRF }, function(r){ if (!r.success) Swal.fire({icon:'error',title:'Error',text:r.message}); }, 'json');
    });
};

function opStatus(id,status){ $.post('<?= buildUrl('api/manage_opening.php') ?>', { action:'change_status', opening_id:id, status, _csrf:RC_CSRF }, function(r){ if (r.success) loadOpenings(); else Swal.fire({icon:'error',title:'Error',text:r.message}); }, 'json'); }
window.opStatus=opStatus;

$(function(){
    loadOpenings();
    $('#cand_opening,#cand_stage').on('change', loadCandidates);
    $('button[data-bs-target="#pane-candidates"]').on('shown.bs.tab', loadCandidates);
});

<?php if ($can_create): ?>
window.openOpeningModal=function(){ $('#openingForm')[0].reset(); $('#op_id').val(''); $('#openingModalTitle').text('New Opening'); new bootstrap.Modal(document.getElementById('openingModal')).show(); };
window.editOpening=function(id){ const o=OPENINGS.find(x=>Number(x.opening_id)===id); if(!o) return;
    $.getJSON('<?= buildUrl('api/get_openings.php') ?>', { opening_id:id }, function(res){ if (!res.success) return; const d=res.data;
        $('#op_id').val(id); $('#op_title').val(d.job_title); $('#op_count').val(d.openings_count); $('#op_close').val(d.close_date||''); $('#op_desig').val(d.designation_id||''); $('#op_dept').val(d.department_id||''); $('#op_desc').val(d.description||''); $('#op_req').val(d.requirements||'');
        $('#openingModalTitle').text('Edit Opening'); new bootstrap.Modal(document.getElementById('openingModal')).show();
    });
};
$('#openingForm').on('submit', function(e){ e.preventDefault();
    $.post('<?= buildUrl('api/manage_opening.php') ?>', $(this).serialize()+'&action='+($('#op_id').val()?'update':'add'), function(r){
        if (r.success){ bootstrap.Modal.getInstance(document.getElementById('openingModal')).hide(); loadOpenings(); Swal.fire({icon:'success',title:'Saved!',text:r.message,timer:1400,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json');
});
window.openCandidateModal=function(){ $('#candidateForm')[0].reset(); if (!OPENINGS.filter(o=>o.status==='open').length){ Swal.fire({icon:'info',title:'No open positions',text:'Create an open opening first.'}); return; } new bootstrap.Modal(document.getElementById('candidateModal')).show(); };
$('#candidateForm').on('submit', function(e){ e.preventDefault();
    const btn=$(this).find('[type="submit"]'); btn.prop('disabled',true);
    $.ajax({ url:'<?= buildUrl('api/manage_candidate.php') ?>', type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
        success:r=>{ if (r.success){ bootstrap.Modal.getInstance(document.getElementById('candidateModal')).hide(); loadCandidates(); loadOpenings(); Swal.fire({icon:'success',title:'Added!',text:r.message,timer:1400,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message}); },
        error:()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}), complete:()=>btn.prop('disabled',false) });
});
<?php endif; ?>
</script>

<?php includeFooter(); ?>
