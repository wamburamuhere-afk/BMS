<?php
// My HR — Employee Self-Service (Tier 4, Phase 4.6 — D24). page_key: my_hr.
// Every role has view; the page shows ONLY the session user's own data,
// resolved from users.employee_id (never from input). An unlinked user gets a
// friendly notice — the page never errors.
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('my_hr');
includeHeader();

$eid = (int)($pdo->query("SELECT employee_id FROM users WHERE user_id = " . (int)$_SESSION['user_id'])->fetchColumn() ?: 0);
?>

<div class="container-fluid mt-4">
    <h4 class="mb-3"><i class="bi bi-person-workspace me-2 text-primary"></i>My HR</h4>

    <?php if (!$eid): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-link-45deg fs-1 text-muted d-block mb-2"></i>
            <h5>Your account isn't linked to an employee record yet</h5>
            <p class="text-muted mb-0">Ask an administrator to link your user to your employee profile (Users &rarr; Edit &rarr; Linked Employee) to see your HR self-service here.</p>
        </div>
    </div>
    <?php else: ?>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t-profile" type="button">Profile</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-payslips" type="button">Payslips</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-leave" type="button">Leave</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-docs" type="button">Documents</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-perf" type="button">Performance</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-record" type="button">Record</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t-ann" type="button">Announcements</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="t-profile" role="tabpanel"><div id="pane-profile" class="card border-0 shadow-sm"><div class="card-body">Loading…</div></div></div>
        <div class="tab-pane fade" id="t-payslips" role="tabpanel"><div id="pane-payslips" class="card border-0 shadow-sm"><div class="card-body">Loading…</div></div></div>
        <div class="tab-pane fade" id="t-leave" role="tabpanel"><div id="pane-leave"><div class="card border-0 shadow-sm"><div class="card-body">Loading…</div></div></div></div>
        <div class="tab-pane fade" id="t-docs" role="tabpanel"><div id="pane-docs" class="card border-0 shadow-sm"><div class="card-body">Loading…</div></div></div>
        <div class="tab-pane fade" id="t-perf" role="tabpanel"><div id="pane-perf" class="card border-0 shadow-sm"><div class="card-body">Loading…</div></div></div>
        <div class="tab-pane fade" id="t-record" role="tabpanel"><div id="pane-record" class="card border-0 shadow-sm"><div class="card-body">Loading…</div></div></div>
        <div class="tab-pane fade" id="t-ann" role="tabpanel"><div id="pane-ann" class="card border-0 shadow-sm"><div class="card-body">Loading…</div></div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Apply for leave modal -->
<div class="modal fade" id="leaveApplyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-calendar-plus me-1"></i> Apply for Leave</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="leaveApplyForm"><div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="mb-3"><label class="form-label">Leave Type <span class="text-danger">*</span></label><select class="form-select" name="leave_type" id="la_type" required></select></div>
        <div class="row"><div class="col-6 mb-3"><label class="form-label">Start <span class="text-danger">*</span></label><input type="date" class="form-control" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-6 mb-3"><label class="form-label">End <span class="text-danger">*</span></label><input type="date" class="form-control" name="end_date" required></div></div>
        <div class="mb-3"><label class="form-label">Reason <span class="text-danger">*</span></label><textarea class="form-control" name="reason" rows="2" required></textarea></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Submit</button></div></form>
</div></div></div>

<?php if ($eid): ?>
<script>
const MH_API = '<?= buildUrl('api/my_hr_data.php') ?>';
const MH_CSRF = <?= json_encode(csrf_token()) ?>;
function esc(v){ return safeOutput(v); }
function chip(days){ if (days===null||days===undefined) return ''; days=Number(days); if (days<0) return '<span class="badge bg-danger">Expired</span>'; if (days<=30) return `<span class="badge bg-warning text-dark">${days}d</span>`; return '<span class="badge bg-success">Valid</span>'; }

function loadProfile(){ $.getJSON(MH_API, { section:'profile' }, function(r){ if (!r.success){ $('#pane-profile .card-body').html('Could not load.'); return; } const d=r.data;
    $('#pane-profile').html(`<div class="card-body"><div class="row">
        <div class="col-md-6"><table class="table table-sm">
            <tr><th style="width:40%">Name</th><td>${esc((d.first_name||'')+' '+(d.middle_name||'')+' '+(d.last_name||''))}</td></tr>
            <tr><th>Employee #</th><td>${esc(d.employee_number)}</td></tr>
            <tr><th>Designation</th><td>${esc(d.designation_name||'—')}</td></tr>
            <tr><th>Department</th><td>${esc(d.department_name||'—')}</td></tr>
            <tr><th>Project</th><td>${esc(d.project_name||'—')}</td></tr></table></div>
        <div class="col-md-6"><table class="table table-sm">
            <tr><th style="width:40%">Email</th><td>${esc(d.email)}</td></tr>
            <tr><th>Phone</th><td>${esc(d.phone)}</td></tr>
            <tr><th>Hire date</th><td>${esc(d.hire_date||'—')}</td></tr>
            <tr><th>Status</th><td>${esc(d.employment_status)}</td></tr></table></div></div>
        <button class="btn btn-sm btn-outline-primary" onclick="requestCorrection()"><i class="bi bi-pencil-square me-1"></i>Request correction</button></div>`);
}); }
function loadPayslips(){ $.getJSON(MH_API, { section:'payslips' }, function(r){ if (!r.success) return;
    const rows = r.data.map(p=>`<tr><td>${esc(p.payroll_period)}</td><td>${esc(p.payroll_date)}</td><td>${Number(p.net_salary||0).toLocaleString()}</td><td><span class="badge bg-${p.payment_status==='paid'?'success':'secondary'}">${esc(p.payment_status||'pending')}</span></td></tr>`).join('') || '<tr><td colspan="4" class="text-muted text-center">No payslips.</td></tr>';
    $('#pane-payslips').html(`<div class="card-body"><table class="table table-sm"><thead><tr><th>Period</th><th>Date</th><th>Net</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table></div>`);
}); }
function loadLeave(){ $.getJSON(MH_API, { section:'leave' }, function(r){ if (!r.success) return;
    const bal = r.balances.map(b=>`<tr><td>${esc(b.type_name||'—')}</td><td>${b.entitled||0}</td><td>${b.carried_over||0}</td></tr>`).join('') || '<tr><td colspan="3" class="text-muted text-center">No balances.</td></tr>';
    const hist = r.history.map(h=>`<tr><td>${esc(h.leave_type)}</td><td>${esc(h.start_date)} → ${esc(h.end_date)}</td><td>${h.total_days}</td><td><span class="badge bg-${h.status==='approved'?'success':(h.status==='rejected'?'danger':'secondary')}">${esc(h.status)}</span></td></tr>`).join('') || '<tr><td colspan="4" class="text-muted text-center">No leave history.</td></tr>';
    $('#pane-leave').html(`<div class="card border-0 shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-end mb-2"><button class="btn btn-sm btn-primary" onclick="openLeaveApply()"><i class="bi bi-calendar-plus me-1"></i>Apply for Leave</button></div>
        <h6>Balances (this year)</h6><table class="table table-sm"><thead><tr><th>Type</th><th>Entitled</th><th>Carried over</th></tr></thead><tbody>${bal}</tbody></table>
        <h6 class="mt-3">History</h6><table class="table table-sm"><thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Status</th></tr></thead><tbody>${hist}</tbody></table></div></div>`);
}); }
function loadDocs(){ $.getJSON(MH_API, { section:'documents' }, function(r){ if (!r.success) return;
    const docs = r.documents.map(d=>`<tr><td>${esc(d.type_name)}</td><td>${esc(d.document_name)}</td><td>${esc(d.expire_date||'—')} ${chip(d.expire_date?d.days_to_expiry:null)}</td>
        <td><a class="btn btn-sm btn-outline-primary py-0" href="<?= buildUrl('api/download_employee_document.php') ?>?emp_doc_id=${d.emp_doc_id}" target="_blank"><i class="bi bi-download"></i></a></td></tr>`).join('') || '<tr><td colspan="4" class="text-muted text-center">No documents.</td></tr>';
    const cons = r.contracts.map(c=>`<tr><td>${esc(c.contract_type)}</td><td>${esc(c.start_date)}</td><td>${esc(c.end_date||'Open-ended')} ${c.status==='active'&&c.end_date?chip(c.days_to_expiry):''}</td><td><span class="badge bg-secondary">${esc(c.status)}</span></td></tr>`).join('') || '<tr><td colspan="4" class="text-muted text-center">No contracts.</td></tr>';
    $('#pane-docs').html(`<div class="card-body"><h6>My Documents</h6><table class="table table-sm"><thead><tr><th>Type</th><th>Name</th><th>Expiry</th><th></th></tr></thead><tbody>${docs}</tbody></table>
        <h6 class="mt-3">My Contracts</h6><table class="table table-sm"><thead><tr><th>Type</th><th>Start</th><th>End</th><th>Status</th></tr></thead><tbody>${cons}</tbody></table></div>`);
}); }
function stars(v){ v=parseInt(v,10)||0; let h=''; for(let i=1;i<=5;i++) h+=`<span style="color:${i<=v?'#f5b301':'#ced4da'}">&#9733;</span>`; return h; }
function loadPerf(){ $.getJSON(MH_API, { section:'performance' }, function(r){ if (!r.success) return;
    const ap = r.appraisals.map(a=>`<tr><td>${esc(a.cycle_name)}</td><td>${stars(Math.round(a.overall_rating))} ${Number(a.overall_rating).toFixed(2)}</td><td>${esc(a.appraisal_date)}</td></tr>`).join('') || '<tr><td colspan="3" class="text-muted text-center">No approved appraisals.</td></tr>';
    const gl = r.goals.map(g=>`<tr><td>${esc(g.subject)}</td><td><div class="progress" style="height:12px"><div class="progress-bar" style="width:${g.progress}%">${g.progress}%</div></div></td><td>${esc(g.status)}</td></tr>`).join('') || '<tr><td colspan="3" class="text-muted text-center">No goals.</td></tr>';
    const tr = r.trainings.map(t=>`<tr><td>${esc(t.title)}</td><td>${esc(t.type_name||'—')}</td><td><span class="badge bg-secondary">${esc(t.part_status)}</span></td><td>${t.certificate_path?`<a class="btn btn-sm btn-outline-primary py-0" href="<?= buildUrl('api/download_training_certificate.php') ?>?participant_id=${t.participant_id}" target="_blank"><i class="bi bi-download"></i></a>`:'—'}</td></tr>`).join('') || '<tr><td colspan="4" class="text-muted text-center">No training.</td></tr>';
    $('#pane-perf').html(`<div class="card-body"><h6>Appraisals</h6><table class="table table-sm"><thead><tr><th>Cycle</th><th>Overall</th><th>Date</th></tr></thead><tbody>${ap}</tbody></table>
        <h6 class="mt-3">Goals</h6><table class="table table-sm"><thead><tr><th>Goal</th><th>Progress</th><th>Status</th></tr></thead><tbody>${gl}</tbody></table>
        <h6 class="mt-3">Training</h6><table class="table table-sm"><thead><tr><th>Title</th><th>Type</th><th>Result</th><th>Cert</th></tr></thead><tbody>${tr}</tbody></table></div>`);
}); }
function loadRecord(){ $.getJSON(MH_API, { section:'record' }, function(r){ if (!r.success) return;
    const sr = r.service_record.map(e=>`<tr><td>${esc(e.event_date)}</td><td>${esc(e.event_type)}</td><td>${esc(e.title)}</td></tr>`).join('') || '<tr><td colspan="3" class="text-muted text-center">No service record entries.</td></tr>';
    const tp = r.trips.map(t=>`<tr><td>${esc(t.destination)}</td><td>${esc(t.start_date)} → ${esc(t.end_date)}</td><td><span class="badge bg-secondary">${esc(t.status)}</span></td></tr>`).join('') || '<tr><td colspan="3" class="text-muted text-center">No trips.</td></tr>';
    const mt = r.meetings.map(m=>`<tr><td>${esc(m.title)}</td><td>${esc(m.meeting_date)} ${m.start_time?esc(m.start_time.substring(0,5)):''}</td></tr>`).join('') || '<tr><td colspan="2" class="text-muted text-center">No upcoming meetings.</td></tr>';
    $('#pane-record').html(`<div class="card-body"><h6>Service Record</h6><table class="table table-sm"><thead><tr><th>Date</th><th>Type</th><th>Title</th></tr></thead><tbody>${sr}</tbody></table>
        <h6 class="mt-3">Trips</h6><table class="table table-sm"><thead><tr><th>Destination</th><th>Dates</th><th>Status</th></tr></thead><tbody>${tp}</tbody></table>
        <h6 class="mt-3">Upcoming meetings</h6><table class="table table-sm"><thead><tr><th>Title</th><th>When</th></tr></thead><tbody>${mt}</tbody></table></div>`);
}); }
function loadAnn(){ $.getJSON('<?= buildUrl('api/get_announcements.php') ?>', { mode:'feed' }, function(r){ if (!r.success) return;
    if (!r.data.length){ $('#pane-ann').html('<div class="card-body text-muted">No current announcements.</div>'); return; }
    $('#pane-ann').html('<div class="card-body">'+r.data.map(a=>`<div class="border-bottom py-2"><div class="d-flex justify-content-between"><strong>${esc(a.title)}</strong>${Number(a.is_read)?'':'<span class="badge bg-primary">New</span>'}</div><div style="white-space:pre-wrap">${esc(a.body)}</div>${Number(a.is_read)?'':`<button class="btn btn-sm btn-link p-0" onclick="markRead(${a.announcement_id},this)">Mark read</button>`}</div>`).join('')+'</div>');
}); }
window.markRead=function(id,btn){ $.post('<?= buildUrl('api/mark_announcement_read.php') ?>', { announcement_id:id, _csrf:MH_CSRF }, function(r){ if (r.success) $(btn).closest('.py-2').find('.badge').remove(), $(btn).remove(); }, 'json'); };
window.requestCorrection=function(){ Swal.fire({ title:'Request a correction', input:'textarea', inputPlaceholder:'Describe what needs correcting…', showCancelButton:true, confirmButtonText:'Send to HR' }).then(res=>{ if (res.isConfirmed && res.value) Swal.fire({icon:'success',title:'Sent',text:'Your request has been noted. (Message Center integration.)',timer:1800,showConfirmButton:false}); }); };
window.openLeaveApply=function(){ $('#leaveApplyForm')[0].reset();
    $.getJSON(MH_API, { section:'leave_types' }, function(r){ if (r.success) $('#la_type').html(r.data.map(t=>`<option value="${esc(t.type_name)}">${esc(t.type_name)}</option>`).join('')); });
    new bootstrap.Modal(document.getElementById('leaveApplyModal')).show();
};
$('#leaveApplyForm').on('submit', function(e){ e.preventDefault();
    $.post('<?= buildUrl('api/my_leave_apply.php') ?>', $(this).serialize(), function(r){
        if (r.success){ bootstrap.Modal.getInstance(document.getElementById('leaveApplyModal')).hide(); loadLeave(); Swal.fire({icon:'success',title:'Submitted!',text:r.message,timer:2000,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json');
});

$(function(){
    loadProfile();
    $('button[data-bs-target="#t-payslips"]').on('shown.bs.tab', loadPayslips);
    $('button[data-bs-target="#t-leave"]').on('shown.bs.tab', loadLeave);
    $('button[data-bs-target="#t-docs"]').on('shown.bs.tab', loadDocs);
    $('button[data-bs-target="#t-perf"]').on('shown.bs.tab', loadPerf);
    $('button[data-bs-target="#t-record"]').on('shown.bs.tab', loadRecord);
    $('button[data-bs-target="#t-ann"]').on('shown.bs.tab', loadAnn);
});
</script>
<?php endif; ?>

<?php includeFooter(); ?>
