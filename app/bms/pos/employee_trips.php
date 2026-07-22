<?php
// Business Trips — Tier 4, Phase 4.3. page_key: employee_trips.
// GL-integrated (D26 follow-up): approving a trip posts an accrual against the
// chosen Expense Account; marking it paid settles that accrual against a
// Paid-From bank/cash account; cancelling/deleting reverses whatever was posted.
// See api/manage_trip.php + core/expense_posting.php.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php'; // expenseAccounts / cashBankAccounts

autoEnforcePermission('employee_trips');
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View trips', 'User viewed the Business Trips page');

$can_create = canCreate('employee_trips');
$can_edit   = canEdit('employee_trips');
$can_delete = canDelete('employee_trips');
$can_approve = canApprove('employee_trips');
$can_reject  = function_exists('canReject') ? canReject('employee_trips') : $can_approve;
$expense_accounts = expenseAccounts($pdo);
$bank_accounts = cashBankAccounts($pdo);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-airplane me-2 text-primary"></i>Business Trips</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" onclick="openTripModal()"><i class="bi bi-plus-circle me-1"></i> New Trip Request</button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4"><div class="card text-center p-3" style="background:#fff3cd;border:1px solid #ffe69c"><div class="fs-4 fw-bold text-warning" id="tps_pending">0</div><div class="small text-muted">Pending</div></div></div>
        <div class="col-6 col-md-4"><div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><div class="fs-4 fw-bold text-primary" id="tps_approved">0</div><div class="small text-muted">Approved &amp; ongoing</div></div></div>
        <div class="col-6 col-md-4"><div class="card text-center p-3" style="background:#d1e7dd;border:1px solid #a3cfbb"><div class="fs-4 fw-bold text-success" id="tps_completed">0</div><div class="small text-muted">Completed this year</div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3"><label class="form-label small mb-1">Status</label>
                <select class="form-select form-select-sm" id="tpf_status"><option value="">All statuses</option>
                    <option value="pending">Pending</option><option value="approved">Approved</option>
                    <option value="completed">Completed</option><option value="paid">Paid</option>
                    <option value="rejected">Rejected</option><option value="cancelled">Cancelled</option></select></div>
            <div class="col-12 col-md-4"><label class="form-label small mb-1">Employee</label><select class="form-select form-select-sm" id="tpf_employee"><option value="">All employees</option></select></div>
            <div class="col-12 col-md-2"><button class="btn btn-sm btn-outline-secondary w-100" id="tpf_reset"><i class="bi bi-arrow-clockwise"></i></button></div>
        </div>
    </div></div>

    <div id="tpTableView" class="card border-0 shadow-sm"><div class="card-body">
        <table id="tripsTable" class="table table-hover align-middle w-100">
            <thead style="--bs-table-color:#fff;--bs-table-bg:#0d6efd;"><tr><th class="text-center">S/NO</th><th>Employee</th><th>Destination</th><th>Dates</th><th>Est. cost</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody></tbody>
        </table>
    </div></div>
    <div id="tpCardView" class="row g-2 d-none"></div>
</div>

<div class="modal fade" id="tripViewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-airplane me-1"></i> Trip Details</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="tripViewBody"></div>
</div></div></div>

<?php if ($can_create): ?>
<div class="modal fade" id="tripModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-airplane me-1"></i> New Trip Request</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="tripForm">
        <div class="modal-body">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="add">
            <div class="mb-3"><label class="form-label">Employee <span class="text-danger">*</span></label><select class="form-select" name="employee_id" id="tp_employee" required></select></div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Destination <span class="text-danger">*</span></label><input class="form-control" name="destination" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">Purpose <span class="text-danger">*</span></label><input class="form-control" name="purpose" required></div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Start <span class="text-danger">*</span></label><input type="date" class="form-control" name="start_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">End <span class="text-danger">*</span></label><input type="date" class="form-control" name="end_date" required></div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3"><label class="form-label">Estimated Cost</label><input type="number" min="0" step="0.01" class="form-control" name="estimated_cost"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Requested Advance</label><input type="number" min="0" step="0.01" class="form-control" name="requested_advance"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Expense Account</label>
                    <select class="form-select select2-static" name="expense_account_id" id="tp_expense_account">
                        <option value="">Select account…</option>
                        <?php foreach ($expense_accounts as $acc): ?>
                            <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars((!empty($acc['account_code']) ? $acc['account_code'] . ' — ' : '') . $acc['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3"><label class="form-label">Reference / Note</label><input class="form-control" name="expense_reference" placeholder="Optional note (e.g. petty-cash slip #)"></div>
            <div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i> Once approved, the estimated cost is posted to the chosen Expense Account. Marking the trip Paid settles it against a Paid-From account.</div>
            <div class="mb-3"><label class="form-label">Attachment</label><input type="file" class="form-control" name="attachment"><div class="form-text">PDF, Word, Excel or image. Max 10MB.</div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Submit Request</button></div>
    </form>
</div></div></div>
<?php endif; ?>

<?php if ($can_edit): ?>
<div class="modal fade" id="tripEditModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-warning text-dark"><h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit Trip Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="tripEditForm">
        <div class="modal-body">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="trip_id" id="tpe_trip_id">
            <div class="mb-3"><label class="form-label">Employee <span class="text-danger">*</span></label><select class="form-select" name="employee_id" id="tpe_employee" required></select></div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Destination <span class="text-danger">*</span></label><input class="form-control" name="destination" id="tpe_destination" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">Purpose <span class="text-danger">*</span></label><input class="form-control" name="purpose" id="tpe_purpose" required></div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Start <span class="text-danger">*</span></label><input type="date" class="form-control" name="start_date" id="tpe_start" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">End <span class="text-danger">*</span></label><input type="date" class="form-control" name="end_date" id="tpe_end" required></div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3"><label class="form-label">Estimated Cost</label><input type="number" min="0" step="0.01" class="form-control" name="estimated_cost" id="tpe_estimated_cost"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Requested Advance</label><input type="number" min="0" step="0.01" class="form-control" name="requested_advance" id="tpe_requested_advance"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Expense Account</label>
                    <select class="form-select select2-static" name="expense_account_id" id="tpe_expense_account">
                        <option value="">Select account…</option>
                        <?php foreach ($expense_accounts as $acc): ?>
                            <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars((!empty($acc['account_code']) ? $acc['account_code'] . ' — ' : '') . $acc['account_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3"><label class="form-label">Reference / Note</label><input class="form-control" name="expense_reference" id="tpe_reference" placeholder="Optional note (e.g. petty-cash slip #)"></div>
            <div class="mb-3"><label class="form-label">Replace Attachment</label><input type="file" class="form-control" name="attachment"><div class="form-text">Leave blank to keep the existing attachment. PDF, Word, Excel or image. Max 10MB.</div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Update Request</button></div>
    </form>
</div></div></div>
<?php endif; ?>

<?php if ($can_edit): ?>
<div class="modal fade" id="tripPayModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i> Mark Trip Paid</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="tripPayForm">
        <div class="modal-body">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="status" value="paid">
            <input type="hidden" name="trip_id" id="tp_pay_trip_id">
            <div class="mb-3"><label class="form-label">Paid From <span class="text-danger">*</span></label>
                <select class="form-select select2-static" name="paid_from_account_id" id="tp_pay_from" required>
                    <option value="">Select account…</option>
                    <?php foreach ($bank_accounts as $acc): ?>
                        <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars((!empty($acc['account_code']) ? $acc['account_code'] . ' — ' : '') . $acc['account_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Payment Date <span class="text-danger">*</span></label><input type="date" class="form-control" name="payment_date" id="tp_pay_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">Amount Paid <span class="text-danger">*</span></label><input type="number" min="0.01" step="0.01" class="form-control" name="paid_amount" id="tp_pay_amount" required></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Post Payment</button></div>
    </form>
</div></div></div>
<?php endif; ?>

<script>
// HTML-escape helper (page-local per app convention)
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }
const TP_CSRF = <?= json_encode(csrf_token()) ?>;
const TP_CAN_EDIT=<?= json_encode($can_edit) ?>, TP_CAN_DELETE=<?= json_encode($can_delete) ?>, TP_CAN_APPROVE=<?= json_encode($can_approve) ?>, TP_CAN_REJECT=<?= json_encode($can_reject) ?>;
const TP_MY_ID = <?= (int)$_SESSION['user_id'] ?>;
let tpTable=null, TP_ROWS=[];

function tpStatusBadge(s){ const m={pending:['#fff3cd','#664d03'],approved:['#0d6efd','#fff'],completed:['#198754','#fff'],paid:['#0f5132','#fff'],rejected:['#dc3545','#fff'],cancelled:['#6c757d','#fff']}; const [bg,fg]=m[s]||['#e9ecef','#495057']; return `<span class="badge" style="background:${bg};color:${fg}">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`; }
function tpActions(r){
    let items = `<li><button class="dropdown-item py-2" onclick="viewTrip(${r.trip_id})"><i class="bi bi-eye text-primary me-2"></i>View</button></li>`;
    if (r.status==='pending' && TP_CAN_EDIT) items += `<li><button class="dropdown-item py-2" onclick="editTrip(${r.trip_id})"><i class="bi bi-pencil text-warning me-2"></i>Edit</button></li>`;
    if (r.status==='pending' && TP_CAN_APPROVE) items += `<li><button class="dropdown-item py-2" onclick="tripAction(${r.trip_id},'approved')"><i class="bi bi-check2-all text-primary me-2"></i>Approve</button></li>`;
    if (r.status==='pending' && TP_CAN_REJECT) items += `<li><button class="dropdown-item py-2 text-danger" onclick="tripAction(${r.trip_id},'rejected')"><i class="bi bi-slash-circle text-danger me-2"></i>Reject</button></li>`;
    if (r.status==='approved' && TP_CAN_EDIT) items += `<li><button class="dropdown-item py-2" onclick="tripAction(${r.trip_id},'completed')"><i class="bi bi-flag text-success me-2"></i>Complete</button></li>`;
    if ((r.status==='approved'||r.status==='completed') && TP_CAN_EDIT) items += `<li><button class="dropdown-item py-2" onclick="openTripPayModal(${r.trip_id},${r.estimated_cost||0})"><i class="bi bi-cash-coin text-success me-2"></i>Mark Paid</button></li>`;
    if (['pending','approved','completed','paid'].includes(r.status) && TP_CAN_EDIT) items += `<li><button class="dropdown-item py-2" onclick="tripAction(${r.trip_id},'cancelled')"><i class="bi bi-x-circle me-2"></i>Cancel</button></li>`;
    if (TP_CAN_DELETE) items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 text-danger" onclick="tripDelete(${r.trip_id})"><i class="bi bi-trash text-danger me-2"></i>Delete</button></li>`;
    return `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle px-2" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
}
function loadTrips(){
    $.getJSON('<?= buildUrl('api/get_trips.php') ?>', { status:$('#tpf_status').val(), employee_id:$('#tpf_employee').val()||'' }, function(res){
        if (!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message||'Could not load.'}); return; }
        TP_ROWS=res.data;
        $('#tps_pending').text(res.stats.pending); $('#tps_approved').text(res.stats.approved); $('#tps_completed').text(res.stats.completed_year);
        tpTable.clear().rows.add(res.data.map(r=>[
            '',
            `<a href="<?= getUrl('employee_details') ?>?id=${r.employee_id}" class="text-decoration-none fw-semibold">${safeOutput(r.first_name+' '+r.last_name)}</a>`,
            safeOutput(r.destination), safeOutput(r.start_date)+' → '+safeOutput(r.end_date),
            r.estimated_cost?Number(r.estimated_cost).toLocaleString():'—', tpStatusBadge(r.status), tpActions(r)
        ])).draw();
    });
}
function tpCards(rows){
    if (!rows.length){ $('#tpCardView').html('<div class="col-12 text-center py-5 text-muted">No trips yet.</div>'); return; }
    $('#tpCardView').html(rows.map(r=>`<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
        <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(r.destination)}</div>${tpStatusBadge(r.status)}</div>
        <div class="small text-muted mt-1">${safeOutput(r.first_name+' '+r.last_name)} · ${safeOutput(r.start_date)} → ${safeOutput(r.end_date)}</div>
        <button class="btn btn-sm btn-outline-primary mt-2" onclick="viewTrip(${r.trip_id})"><i class="bi bi-eye"></i> View</button></div></div></div>`).join(''));
}
function viewTrip(id){
    $.getJSON('<?= buildUrl('api/get_trips.php') ?>', { trip_id:id }, function(res){
        if (!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message}); return; }
        const t=res.data;
        $('#tripViewBody').html(`
            <div class="d-flex justify-content-between flex-wrap mb-2"><div><div class="fs-5 fw-bold">${safeOutput(t.destination)}</div><div class="small text-muted">${safeOutput(t.first_name+' '+t.last_name)}</div></div><div>${tpStatusBadge(t.status)}</div></div>
            <table class="table table-sm">
                <tr><th style="width:35%">Purpose</th><td>${safeOutput(t.purpose)}</td></tr>
                <tr><th>Dates</th><td>${safeOutput(t.start_date)} → ${safeOutput(t.end_date)}</td></tr>
                ${t.estimated_cost?`<tr><th>Estimated cost</th><td>${Number(t.estimated_cost).toLocaleString()}</td></tr>`:''}
                ${t.requested_advance?`<tr><th>Requested advance</th><td>${Number(t.requested_advance).toLocaleString()} <span class="text-muted small">(informational)</span></td></tr>`:''}
                ${t.expense_account_name?`<tr><th>Expense account</th><td>${safeOutput((t.expense_account_code?t.expense_account_code+' — ':'')+t.expense_account_name)}</td></tr>`:''}
                ${t.expense_reference?`<tr><th>Reference / note</th><td>${safeOutput(t.expense_reference)}</td></tr>`:''}
                ${t.status==='paid'?`<tr><th>Paid</th><td>${Number(t.paid_amount).toLocaleString()} from ${safeOutput((t.paid_from_account_code?t.paid_from_account_code+' — ':'')+t.paid_from_account_name)} on ${safeOutput(t.payment_date)}</td></tr>`:''}
                ${t.report?`<tr><th>Trip report</th><td>${safeOutput(t.report)}</td></tr>`:''}
                ${t.reject_reason?`<tr><th class="text-danger">Reject reason</th><td class="text-danger">${safeOutput(t.reject_reason)}</td></tr>`:''}
                ${t.approved_by_name?`<tr><th>${t.status==='rejected'?'Rejected':'Approved'} by</th><td>${safeOutput(t.approved_by_name)}</td></tr>`:''}
                ${t.attachment_path?`<tr><th>Attachment</th><td><a href="<?= buildUrl('api/download_trip_attachment.php') ?>?trip_id=${t.trip_id}" target="_blank"><i class="bi bi-download"></i> ${safeOutput(t.attachment_name||'Download')}</a></td></tr>`:''}
            </table>`);
        new bootstrap.Modal(document.getElementById('tripViewModal')).show();
    });
}
function tripAction(id, status){
    const labels={approved:'Approve',rejected:'Reject',completed:'Complete',cancelled:'Cancel'};
    const opts={ title:labels[status]+' trip?', icon: (status==='rejected'||status==='cancelled')?'warning':'question', showCancelButton:true, confirmButtonColor:(status==='rejected')?'#dc3545':'#0d6efd', confirmButtonText:'Yes' };
    if (status==='rejected'){ opts.input='text'; opts.inputPlaceholder='Reason (required)'; opts.inputValidator=v=>!v?'A reason is required':undefined; }
    if (status==='completed'){ opts.input='textarea'; opts.inputPlaceholder='Trip report (required)'; opts.inputValidator=v=>!v?'A trip report is required':undefined; }
    Swal.fire(opts).then(res=>{
        if (!res.isConfirmed) return;
        const data={ action:'change_status', trip_id:id, status, _csrf:TP_CSRF };
        if (status==='rejected') data.reject_reason=res.value||'';
        if (status==='completed') data.report=res.value||'';
        $.post('<?= buildUrl('api/manage_trip.php') ?>', data, function(r){ if (r.success){ loadTrips(); Swal.fire({icon:'success',title:'Done!',text:r.message,timer:2000,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message}); }, 'json');
    });
}
function tripDelete(id){ Swal.fire({title:'Delete trip?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Delete'}).then(r=>{ if(r.isConfirmed) $.post('<?= buildUrl('api/manage_trip.php') ?>',{action:'delete',trip_id:id,_csrf:TP_CSRF},function(res){ if(res.success) loadTrips(); else Swal.fire({icon:'error',title:'Error',text:res.message}); },'json'); }); }
<?php if ($can_edit): ?>
window.editTrip=function(id){
    $.getJSON('<?= buildUrl('api/get_trips.php') ?>', { trip_id:id }, function(res){
        if (!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message}); return; }
        const t=res.data;
        $('#tripEditForm')[0].reset();
        $('#tpe_trip_id').val(t.trip_id);
        $('#tpe_destination').val(t.destination);
        $('#tpe_purpose').val(t.purpose);
        $('#tpe_start').val(t.start_date);
        $('#tpe_end').val(t.end_date);
        $('#tpe_estimated_cost').val(t.estimated_cost || '');
        $('#tpe_requested_advance').val(t.requested_advance || '');
        $('#tpe_reference').val(t.expense_reference || '');
        new bootstrap.Modal(document.getElementById('tripEditModal')).show();
        // Employee + Expense Account selects need their option present before Select2 can set a value.
        setTimeout(function(){
            const empOpt = new Option(t.first_name+' '+t.last_name, t.employee_id, true, true);
            $('#tpe_employee').empty().append(empOpt).trigger('change');
            $('#tpe_expense_account').val(t.expense_account_id || '').trigger('change');
        }, 0);
    });
};
$('#tripEditModal').on('shown.bs.modal', function(){
    if (!$('#tpe_employee').hasClass('select2-hidden-accessible')) $('#tpe_employee').select2({ theme:'bootstrap-5',dropdownParent:$('#tripEditModal'),placeholder:'Select employee…',width:'100%',minimumInputLength:1,ajax:{url:'<?= buildUrl('api/account/search_employees.php') ?>',dataType:'json',delay:300,data:p=>({q:p.term}),processResults:d=>({results:d.results}),cache:true} });
    if (!$('#tpe_expense_account').hasClass('select2-hidden-accessible')) $('#tpe_expense_account').select2({ theme:'bootstrap-5',dropdownParent:$('#tripEditModal'),placeholder:'Select account…',allowClear:true,width:'100%' });
});
$('#tripEditForm').on('submit', function(e){
    e.preventDefault();
    const btn=$(this).find('[type="submit"]'); btn.prop('disabled',true);
    $.ajax({ url:'<?= buildUrl('api/manage_trip.php') ?>', type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
        success:r=>{ if (r.success){ bootstrap.Modal.getInstance(document.getElementById('tripEditModal')).hide(); loadTrips(); Swal.fire({icon:'success',title:'Updated!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message}); },
        error:()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}), complete:()=>btn.prop('disabled',false) });
});
window.openTripPayModal=function(id, estCost){
    $('#tripPayForm')[0].reset();
    $('#tp_pay_trip_id').val(id);
    if (estCost) $('#tp_pay_amount').val(Number(estCost).toFixed(2));
    new bootstrap.Modal(document.getElementById('tripPayModal')).show();
};
$('#tripPayModal').on('shown.bs.modal', function(){
    if (!$('#tp_pay_from').hasClass('select2-hidden-accessible')) $('#tp_pay_from').select2({ theme:'bootstrap-5',dropdownParent:$('#tripPayModal'),width:'100%' });
});
$('#tripPayForm').on('submit', function(e){
    e.preventDefault();
    const btn=$(this).find('[type="submit"]'); btn.prop('disabled',true);
    $.post('<?= buildUrl('api/manage_trip.php') ?>', $(this).serialize(), function(r){
        if (r.success){ bootstrap.Modal.getInstance(document.getElementById('tripPayModal')).hide(); loadTrips(); Swal.fire({icon:'success',title:'Paid!',text:r.message,timer:1600,showConfirmButton:false}); }
        else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json').fail(()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'})).always(()=>btn.prop('disabled',false));
});
<?php endif; ?>

$(function(){
    tpTable=$('#tripsTable').DataTable({ responsive:false,scrollX:true,pageLength:25,order:[[3,'desc']],dom:'rtip',columnDefs:[{targets:0,orderable:false,searchable:false,className:'text-center',render:(d,t,row,meta)=>meta.row+1+meta.settings._iDisplayStart}],language:{emptyTable:'No trips yet.'},drawCallback:function(){ tpCards(this.api().rows({page:'current'})[0].map(i=>TP_ROWS[i]).filter(Boolean)); } });
    $('#tpf_employee').select2({ theme:'bootstrap-5',placeholder:'All employees',allowClear:true,width:'100%',minimumInputLength:1,ajax:{url:'<?= buildUrl('api/account/search_employees.php') ?>',dataType:'json',delay:300,data:p=>({q:p.term}),processResults:d=>({results:d.results}),cache:true} });
    $('#tpf_status,#tpf_employee').on('change', loadTrips);
    $('#tpf_reset').on('click', function(){ $('#tpf_status').val(''); $('#tpf_employee').val(null).trigger('change.select2'); loadTrips(); });
    function tv(){ if (window.innerWidth<768){$('#tpTableView').addClass('d-none');$('#tpCardView').removeClass('d-none');}else{$('#tpTableView').removeClass('d-none');$('#tpCardView').addClass('d-none');} }
    tv(); $(window).on('resize', tv);
    loadTrips();
});

<?php if ($can_create): ?>
window.openTripModal=function(){ $('#tripForm')[0].reset(); new bootstrap.Modal(document.getElementById('tripModal')).show(); };
$('#tripModal').on('shown.bs.modal', function(){
    if (!$('#tp_employee').hasClass('select2-hidden-accessible')) $('#tp_employee').select2({ theme:'bootstrap-5',dropdownParent:$('#tripModal'),placeholder:'Select employee…',width:'100%',minimumInputLength:1,ajax:{url:'<?= buildUrl('api/account/search_employees.php') ?>',dataType:'json',delay:300,data:p=>({q:p.term}),processResults:d=>({results:d.results}),cache:true} });
    if (!$('#tp_expense_account').hasClass('select2-hidden-accessible')) $('#tp_expense_account').select2({ theme:'bootstrap-5',dropdownParent:$('#tripModal'),placeholder:'Select account…',allowClear:true,width:'100%' });
});
$('#tripForm').on('submit', function(e){
    e.preventDefault();
    const btn=$(this).find('[type="submit"]'); btn.prop('disabled',true);
    $.ajax({ url:'<?= buildUrl('api/manage_trip.php') ?>', type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
        success:r=>{ if (r.success){ bootstrap.Modal.getInstance(document.getElementById('tripModal')).hide(); loadTrips(); Swal.fire({icon:'success',title:'Submitted!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message}); },
        error:()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}), complete:()=>btn.prop('disabled',false) });
});
<?php endif; ?>
</script>

<?php includeFooter(); ?>
