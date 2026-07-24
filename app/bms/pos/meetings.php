<?php
// Meetings — Tier 4, Phase 4.3 (D29). page_key: meetings.
// Minimal: schedule + attendees + minutes + status. No rooms/recurrence/video.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/zoom_service.php';

autoEnforcePermission('meetings');
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View meetings', 'User viewed the Meetings page');

$can_create = canCreate('meetings');
$can_edit   = canEdit('meetings');
$can_delete = canDelete('meetings');
$zoom_enabled = zoomConfigured();
$zoom_hosts = $pdo->query("SELECT user_id, username, first_name, last_name FROM users WHERE is_active = 1 ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-calendar-event me-2 text-primary"></i>Meetings</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" onclick="openMeetingModal()"><i class="bi bi-plus-circle me-1"></i> New Meeting</button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4"><div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><div class="fs-4 fw-bold text-primary" id="ms_upcoming">0</div><div class="small text-muted">Upcoming</div></div></div>
        <div class="col-6 col-md-4"><div class="card text-center p-3" style="background:#fff3cd;border:1px solid #ffe69c"><div class="fs-4 fw-bold text-warning" id="ms_week">0</div><div class="small text-muted">This week</div></div></div>
        <div class="col-6 col-md-4"><div class="card text-center p-3" style="background:#d1e7dd;border:1px solid #a3cfbb"><div class="fs-4 fw-bold text-success" id="ms_month">0</div><div class="small text-muted">Completed this month</div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
        <select class="form-select form-select-sm" id="mf_status" style="max-width:220px"><option value="">All statuses</option><option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select>
    </div></div>

    <div id="mtTableView" class="card border-0 shadow-sm"><div class="card-body">
        <table id="meetingsTable" class="table table-hover align-middle w-100">
            <thead style="--bs-table-color:#fff;--bs-table-bg:#0d6efd;"><tr><th class="text-center">S/NO</th><th>Title</th><th>Date</th><th>Time</th><th>Location</th><th>Attendees</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody></tbody>
        </table>
    </div></div>
    <div id="mtCardView" class="row g-2 d-none"></div>
</div>

<div class="modal fade" id="meetingViewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-calendar-event me-1"></i> Meeting</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="meetingViewBody"></div>
</div></div></div>

<?php if ($can_create): ?>
<div class="modal fade" id="meetingModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-calendar-event me-1"></i> <span id="meetingModalTitle">New Meeting</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="meetingForm">
        <div class="modal-body">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="meeting_id" id="mt_id">
            <div class="mb-3"><label class="form-label">Title <span class="text-danger">*</span></label><input class="form-control" name="title" id="mt_title" required maxlength="255"></div>
            <div class="mb-3"><label class="form-label">Agenda</label><textarea class="form-control" name="agenda" id="mt_agenda" rows="2"></textarea></div>
            <div class="mb-3">
                <label class="form-label d-block">Meeting Type</label>
                <div class="btn-group" role="group">
                    <input type="radio" class="btn-check" name="meeting_type" id="mt_type_person" value="in_person" checked>
                    <label class="btn btn-outline-primary btn-sm" for="mt_type_person"><i class="bi bi-geo-alt me-1"></i>In-Person</label>
                    <input type="radio" class="btn-check" name="meeting_type" id="mt_type_zoom" value="zoom" <?= $zoom_enabled ? '' : 'disabled' ?>>
                    <label class="btn btn-outline-primary btn-sm" for="mt_type_zoom" <?= $zoom_enabled ? '' : 'title="Ask an administrator to enable Zoom in Settings → Zoom Integration"' ?>><i class="bi bi-camera-video me-1"></i>Zoom</label>
                </div>
                <?php if (!$zoom_enabled): ?><div class="form-text text-warning"><i class="bi bi-info-circle"></i> Zoom is not enabled — ask an administrator to configure it in Settings → Zoom Integration.</div><?php endif; ?>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3"><label class="form-label">Date <span class="text-danger">*</span></label><input type="date" class="form-control" name="meeting_date" id="mt_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-3 mb-3"><label class="form-label">Start</label><input type="time" class="form-control" name="start_time" id="mt_start"></div>
                <div class="col-md-3 mb-3"><label class="form-label">End</label><input type="time" class="form-control" name="end_time" id="mt_end"></div>
                <div class="col-md-2 mb-3" id="mt_venue_wrap"><label class="form-label">Venue</label><input class="form-control" name="venue" id="mt_venue"></div>
            </div>
            <div class="d-none" id="mt_zoom_wrap">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Host <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="host_user_id" id="mt_host">
                            <option value="">Select host…</option>
                            <?php foreach ($zoom_hosts as $h): ?>
                            <option value="<?= (int)$h['user_id'] ?>"><?= safe_output(trim($h['first_name'].' '.$h['last_name']) ?: $h['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">The BMS user whose Zoom account will host this meeting.</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Password <span class="text-muted small">(optional)</span></label>
                        <input class="form-control" name="zoom_password" id="mt_zoom_password" maxlength="10" placeholder="Leave blank to auto-generate">
                    </div>
                </div>
                <div class="row">
                    <div class="col-6 col-md-3 mb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="zoom_host_video" id="mt_zoom_host_video" value="1" checked><label class="form-check-label" for="mt_zoom_host_video">Host video on</label></div></div>
                    <div class="col-6 col-md-3 mb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="zoom_participant_video" id="mt_zoom_participant_video" value="1"><label class="form-check-label" for="mt_zoom_participant_video">Participant video on</label></div></div>
                    <div class="col-6 col-md-3 mb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="zoom_waiting_room" id="mt_zoom_waiting_room" value="1" checked><label class="form-check-label" for="mt_zoom_waiting_room">Waiting room</label></div></div>
                    <div class="col-6 col-md-3 mb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="zoom_auto_recording" id="mt_zoom_auto_recording" value="1"><label class="form-check-label" for="mt_zoom_auto_recording">Auto-record (cloud)</label></div></div>
                </div>
            </div>
            <div class="mb-3"><label class="form-label">Attendees</label><select class="form-select" name="attendees[]" id="mt_attendees" multiple style="width:100%"></select></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
</div></div></div>
<?php endif; ?>

<script>
// HTML-escape helper (page-local per app convention)
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }
const MT_CSRF = <?= json_encode(csrf_token()) ?>;
const MT_CAN_EDIT=<?= json_encode($can_edit) ?>, MT_CAN_DELETE=<?= json_encode($can_delete) ?>;
const MT_USER_ID = <?= json_encode((int)$_SESSION['user_id']) ?>;
let mtTable=null, MT_ROWS=[], MT_CUR=null;

function mtBadge(s){ const m={scheduled:['#0d6efd','#fff'],completed:['#198754','#fff'],cancelled:['#6c757d','#fff']}; const [bg,fg]=m[s]||['#e9ecef','#495057']; return `<span class="badge" style="background:${bg};color:${fg}">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`; }
function mtLocationCell(r){
    if (r.meeting_type==='zoom'){
        let b = '<span class="badge bg-primary-subtle text-primary border"><i class="bi bi-camera-video"></i> Zoom</span>';
        if (r.zoom_sync_status==='failed') b += ' <span class="badge bg-danger-subtle text-danger border">Sync failed</span>';
        return b;
    }
    return safeOutput(r.venue||'—');
}
function mtActions(r){
    let items = `<li><button class="dropdown-item py-2" onclick="viewMeeting(${r.meeting_id})"><i class="bi bi-eye text-primary me-2"></i>View / Attendance</button></li>`;
    if (MT_CAN_EDIT && r.status==='scheduled'){
        items += `<li><button class="dropdown-item py-2" onclick="editMeeting(${r.meeting_id})"><i class="bi bi-pencil text-primary me-2"></i>Edit</button></li>`;
        items += `<li><button class="dropdown-item py-2" onclick="completeMeeting(${r.meeting_id})"><i class="bi bi-check2-all text-success me-2"></i>Complete</button></li>`;
        items += `<li><button class="dropdown-item py-2 text-danger" onclick="meetingAction(${r.meeting_id},'cancel')"><i class="bi bi-x-circle text-danger me-2"></i>Cancel</button></li>`;
    }
    if (MT_CAN_EDIT && r.zoom_sync_status==='failed'){
        items += `<li><button class="dropdown-item py-2 text-danger" onclick="retryZoomSync(${r.meeting_id})"><i class="bi bi-arrow-clockwise text-danger me-2"></i>Retry Zoom Sync</button></li>`;
    }
    if (MT_CAN_DELETE) items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 text-danger" onclick="meetingAction(${r.meeting_id},'delete')"><i class="bi bi-trash text-danger me-2"></i>Delete</button></li>`;
    return `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle px-2" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
}
function loadMeetings(){
    $.getJSON('<?= buildUrl('api/get_meetings.php') ?>', { status:$('#mf_status').val() }, function(res){
        if (!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message||'Could not load.'}); return; }
        MT_ROWS=res.data;
        $('#ms_upcoming').text(res.stats.upcoming); $('#ms_week').text(res.stats.this_week); $('#ms_month').text(res.stats.completed_month);
        mtTable.clear().rows.add(res.data.map(r=>[
            '', safeOutput(r.title), safeOutput(r.meeting_date),
            (r.start_time?safeOutput(r.start_time.substring(0,5)):'—')+(r.end_time?'–'+safeOutput(r.end_time.substring(0,5)):''),
            mtLocationCell(r), r.attendee_count, mtBadge(r.status), mtActions(r)
        ])).draw();
    });
}
function mtCards(rows){
    if (!rows.length){ $('#mtCardView').html('<div class="col-12 text-center py-5 text-muted">No meetings yet.</div>'); return; }
    $('#mtCardView').html(rows.map(r=>`<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
        <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(r.title)}</div>${mtBadge(r.status)}</div>
        <div class="small text-muted mt-1">${safeOutput(r.meeting_date)} · ${mtLocationCell(r)} · ${r.attendee_count} attendee(s)</div>
        <button class="btn btn-sm btn-outline-primary mt-2" onclick="viewMeeting(${r.meeting_id})"><i class="bi bi-eye"></i> View</button></div></div></div>`).join(''));
}
function viewMeeting(id){
    $.getJSON('<?= buildUrl('api/get_meetings.php') ?>', { meeting_id:id }, function(res){
        if (!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message}); return; }
        MT_CUR=res.data;
        const m=res.data;
        const editable = MT_CAN_EDIT && m.status==='scheduled';
        let prows = res.attendees.map(a=>`<tr><td>${safeOutput(a.first_name+' '+a.last_name)}</td><td>${editable?`<input type="checkbox" class="att-chk" data-eid="${a.employee_id}" ${Number(a.attended)===1?'checked':''}>`:(a.attended===null?'<span class="text-muted">—</span>':(Number(a.attended)?'<span class="badge bg-success">Present</span>':'<span class="badge bg-secondary">Absent</span>'))}</td></tr>`).join('');
        if (!res.attendees.length) prows = '<tr><td colspan="2" class="text-muted text-center">No attendees.</td></tr>';
        let zoomBlock = '';
        if (m.meeting_type==='zoom'){
            // Start URL carries Zoom host privileges — shown only to the designated
            // host or the meeting's creator, never to attendees (Zoom's own convention).
            const isHost = MT_USER_ID===Number(m.host_user_id) || MT_USER_ID===Number(m.created_by);
            zoomBlock = `<div class="alert alert-light border mb-2">
                <div><i class="bi bi-camera-video text-primary"></i> <strong>Zoom Meeting</strong></div>
                ${m.zoom_sync_status==='failed' ? `<div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle"></i> Zoom sync failed.${(MT_CAN_EDIT && m.status!=='completed') ? ` <button class="btn btn-sm btn-outline-danger ms-1 py-0" onclick="retryZoomSync(${m.meeting_id})"><i class="bi bi-arrow-clockwise"></i> Retry</button>` : ''}</div>` : ''}
                ${m.zoom_join_url ? `<div class="mt-2"><a href="${m.zoom_join_url}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-up-right"></i> Join Meeting</a>${m.zoom_password ? ` <span class="small text-muted">Password: ${safeOutput(m.zoom_password)}</span>` : ''}</div>` : ''}
                ${isHost && m.zoom_start_url ? `<div class="mt-2"><a href="${m.zoom_start_url}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-play-circle"></i> Start Meeting (Host)</a></div>` : ''}
            </div>`;
        }
        $('#meetingViewBody').html(`
            <div class="fs-5 fw-bold">${safeOutput(m.title)}</div>
            <div class="small text-muted mb-2">${safeOutput(m.meeting_date)} ${m.start_time?safeOutput(m.start_time.substring(0,5)):''} · ${m.meeting_type==='zoom'?'Zoom':safeOutput(m.venue||'—')} · ${mtBadge(m.status)}</div>
            ${zoomBlock}
            ${m.agenda?`<div class="mb-2"><strong>Agenda:</strong> ${safeOutput(m.agenda)}</div>`:''}
            ${m.minutes?`<div class="mb-2"><strong>Minutes:</strong><div style="white-space:pre-wrap">${safeOutput(m.minutes)}</div></div>`:''}
            <table class="table table-sm"><thead><tr><th>Attendee</th><th>Attended</th></tr></thead><tbody>${prows}</tbody></table>
            ${editable && res.attendees.length ? `<button class="btn btn-sm btn-primary" onclick="saveAttendance(${m.meeting_id})"><i class="bi bi-save me-1"></i>Save Attendance</button>` : ''}`);
        new bootstrap.Modal(document.getElementById('meetingViewModal')).show();
    });
}
window.retryZoomSync=function(id){
    $.post('<?= buildUrl('api/manage_meeting.php') ?>', { action:'retry_zoom', meeting_id:id, _csrf:MT_CSRF }, function(r){
        if (r.success){ Swal.fire({icon:'success',title:'Synced!',text:r.message,timer:1600,showConfirmButton:false}); loadMeetings(); }
        else Swal.fire({icon:'error',title:'Retry failed',text:r.message});
    }, 'json').fail(()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'}));
};
window.saveAttendance=function(id){
    const present={}; $('.att-chk:checked').each(function(){ present[$(this).data('eid')]=1; });
    $.post('<?= buildUrl('api/manage_meeting.php') ?>', { action:'mark_attendance', meeting_id:id, present, _csrf:MT_CSRF }, function(r){
        if (r.success){ Swal.fire({icon:'success',title:'Saved!',text:r.message,timer:1400,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json');
};
window.completeMeeting=function(id){
    Swal.fire({ title:'Complete meeting', input:'textarea', inputPlaceholder:'Minutes (optional)', showCancelButton:true, confirmButtonText:'Complete' }).then(res=>{
        if (!res.isConfirmed) return;
        $.post('<?= buildUrl('api/manage_meeting.php') ?>', { action:'complete', meeting_id:id, minutes:res.value||'', _csrf:MT_CSRF }, function(r){ if (r.success){ loadMeetings(); Swal.fire({icon:'success',title:'Done!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message}); }, 'json');
    });
};
function meetingAction(id, action){
    Swal.fire({ title: action==='cancel'?'Cancel meeting?':'Delete meeting?', text: action==='cancel'?'Attendees will be notified.':'', icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes' }).then(r=>{
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/manage_meeting.php') ?>', { action, meeting_id:id, _csrf:MT_CSRF }, function(res){ if (res.success){ loadMeetings(); } else Swal.fire({icon:'error',title:'Error',text:res.message}); }, 'json');
    });
}

$(function(){
    mtTable=$('#meetingsTable').DataTable({ responsive:false,scrollX:true,pageLength:25,order:[[2,'desc']],dom:'rtip',columnDefs:[{targets:0,orderable:false,searchable:false,className:'text-center',render:(d,t,row,meta)=>meta.row+1+meta.settings._iDisplayStart}],language:{emptyTable:'No meetings yet.'},drawCallback:function(){ mtCards(this.api().rows({page:'current'})[0].map(i=>MT_ROWS[i]).filter(Boolean)); } });
    $('#mf_status').on('change', loadMeetings);
    function mv(){ if (window.innerWidth<768){$('#mtTableView').addClass('d-none');$('#mtCardView').removeClass('d-none');}else{$('#mtTableView').removeClass('d-none');$('#mtCardView').addClass('d-none');} }
    mv(); $(window).on('resize', mv);
    loadMeetings();
});

<?php if ($can_create): ?>
function attendeeSelect2(){ if (!$('#mt_attendees').hasClass('select2-hidden-accessible')) $('#mt_attendees').select2({ theme:'bootstrap-5',dropdownParent:$('#meetingModal'),placeholder:'Search employees…',width:'100%',minimumInputLength:1,ajax:{url:'<?= buildUrl('api/account/search_employees.php') ?>',dataType:'json',delay:300,data:p=>({q:p.term}),processResults:d=>({results:d.results}),cache:true} }); }
function hostSelect2(){ if (!$('#mt_host').hasClass('select2-hidden-accessible')) $('#mt_host').select2({ theme:'bootstrap-5', dropdownParent:$('#meetingModal'), width:'100%' }); }
function mtToggleType(){
    const isZoom = $('#mt_type_zoom').is(':checked');
    $('#mt_venue_wrap').toggleClass('d-none', isZoom);
    $('#mt_zoom_wrap').toggleClass('d-none', !isZoom);
    $('#mt_host').prop('required', isZoom);
}
$(document).on('change', 'input[name="meeting_type"]', mtToggleType);
window.openMeetingModal=function(){
    $('#meetingForm')[0].reset(); $('#mt_id').val(''); $('#mt_attendees').empty().val(null).trigger('change');
    $('#mt_type_person').prop('checked', true); mtToggleType();
    $('#meetingModalTitle').text('New Meeting'); new bootstrap.Modal(document.getElementById('meetingModal')).show();
};
window.editMeeting=function(id){
    $.getJSON('<?= buildUrl('api/get_meetings.php') ?>', { meeting_id:id }, function(res){
        if (!res.success) return;
        const m=res.data;
        $('#meetingModalTitle').text('Edit Meeting'); $('#mt_id').val(id); $('#mt_title').val(m.title); $('#mt_agenda').val(m.agenda||'');
        $('#mt_date').val(m.meeting_date); $('#mt_start').val(m.start_time?m.start_time.substring(0,5):''); $('#mt_end').val(m.end_time?m.end_time.substring(0,5):''); $('#mt_venue').val(m.venue||'');
        $('#mt_type_' + (m.meeting_type==='zoom' ? 'zoom' : 'person')).prop('checked', true);
        $('#mt_zoom_password').val(m.zoom_password||'');
        $('#mt_zoom_host_video').prop('checked', Number(m.zoom_host_video)===1);
        $('#mt_zoom_participant_video').prop('checked', Number(m.zoom_participant_video)===1);
        $('#mt_zoom_waiting_room').prop('checked', Number(m.zoom_waiting_room)===1);
        $('#mt_zoom_auto_recording').prop('checked', Number(m.zoom_auto_recording)===1);
        mtToggleType();
        new bootstrap.Modal(document.getElementById('meetingModal')).show();
        setTimeout(function(){
            attendeeSelect2(); $('#mt_attendees').empty(); res.attendees.forEach(a=>{ $('#mt_attendees').append(new Option(a.first_name+' '+a.last_name, a.employee_id, true, true)); }); $('#mt_attendees').trigger('change');
            hostSelect2(); $('#mt_host').val(m.host_user_id||'').trigger('change');
        }, 300);
    });
};
$('#meetingModal').on('shown.bs.modal', function(){ attendeeSelect2(); hostSelect2(); });
$('#meetingForm').on('submit', function(e){
    e.preventDefault();
    const btn=$(this).find('[type="submit"]'); btn.prop('disabled',true);
    $.post('<?= buildUrl('api/manage_meeting.php') ?>', $(this).serialize()+'&action='+($('#mt_id').val()?'update':'add'), function(r){
        if (r.success){ bootstrap.Modal.getInstance(document.getElementById('meetingModal')).hide(); loadMeetings(); Swal.fire({icon:'success',title:'Saved!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json').fail(()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'})).always(()=>btn.prop('disabled',false));
});
<?php endif; ?>
</script>

<?php includeFooter(); ?>
