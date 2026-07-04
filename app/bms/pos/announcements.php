<?php
// Announcements — Tier 4, Phase 4.2. page_key: announcements.
// Broadcast (one-to-many) with publish/expiry window; delivery rides the
// existing notifications engine on publish (D25). Distinct from message_center.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/project_scope.php';

autoEnforcePermission('announcements');
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View announcements', 'User viewed the Announcements page');

$can_create = canCreate('announcements');
$can_edit   = canEdit('announcements');
$can_delete = canDelete('announcements');
$can_publish = function_exists('canPublish') ? canPublish('announcements') : $can_edit;

$departments = $pdo->query("SELECT department_id, department_name FROM departments WHERE status='active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
$proj_scope = function_exists('scopeFilterSql') ? scopeFilterSql('project', 'projects') : '';
$projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status NOT IN ('cancelled') $proj_scope ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-megaphone me-2 text-primary"></i>Announcements</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" onclick="openAnnModal()"><i class="bi bi-plus-circle me-1"></i> New Announcement</button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe"><div class="fs-4 fw-bold text-primary" id="as_current">0</div><div class="small text-muted">Published &amp; current</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#e9ecef;border:1px solid #dee2e6"><div class="fs-4 fw-bold text-secondary" id="as_drafts">0</div><div class="small text-muted">Drafts</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#fff3cd;border:1px solid #ffe69c"><div class="fs-4 fw-bold text-warning" id="as_expiring">0</div><div class="small text-muted">Expiring &le;7d</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center p-3" style="background:#d1e7dd;border:1px solid #a3cfbb"><div class="fs-4 fw-bold text-success" id="as_readrate">—</div><div class="small text-muted">Read rate</div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
        <select class="form-select form-select-sm" id="af_status" style="max-width:220px">
            <option value="">All statuses</option><option value="draft">Draft</option>
            <option value="published">Published</option><option value="archived">Archived</option>
        </select>
    </div></div>

    <div id="annTableView" class="card border-0 shadow-sm"><div class="card-body">
        <table id="annTable" class="table table-hover align-middle w-100">
            <thead style="--bs-table-color:#fff;--bs-table-bg:#0d6efd;"><tr><th class="text-center">S/NO</th><th>Title</th><th>Audience</th><th>Publish</th><th>Expire</th><th>Reads</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody></tbody>
        </table>
    </div></div>
    <div id="annCardView" class="row g-2 d-none"></div>
</div>

<div class="modal fade" id="annViewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-megaphone me-1"></i> Announcement</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="annViewBody"></div>
</div></div></div>

<?php if ($can_create): ?>
<div class="modal fade" id="annModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-megaphone me-1"></i> <span id="annModalTitle">New Announcement</span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="annForm">
        <div class="modal-body">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="announcement_id" id="an_id">
            <div class="mb-3"><label class="form-label">Title <span class="text-danger">*</span></label><input class="form-control" name="title" id="an_title" required maxlength="255"></div>
            <div class="mb-3"><label class="form-label">Body <span class="text-danger">*</span></label><textarea class="form-control" name="body" id="an_body" rows="4" required></textarea></div>
            <div class="row">
                <div class="col-md-4 mb-3"><label class="form-label">Priority</label>
                    <select class="form-select" name="priority" id="an_priority"><option value="normal">Normal</option><option value="important">Important</option><option value="urgent">Urgent</option></select></div>
                <div class="col-md-4 mb-3"><label class="form-label">Audience</label>
                    <select class="form-select" name="audience_type" id="an_audience"><option value="all">Everyone</option><option value="department">Department</option><option value="project">Project</option></select></div>
                <div class="col-md-4 mb-3" id="an_dept_wrap" style="display:none"><label class="form-label">Department</label>
                    <select class="form-select" name="department_id" id="an_dept"><option value="">Select…</option><?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>"><?= safe_output($d['department_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 mb-3" id="an_proj_wrap" style="display:none"><label class="form-label">Project</label>
                    <select class="form-select" name="project_id" id="an_proj"><option value="">Select…</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Publish Date <span class="text-danger">*</span></label><input type="date" class="form-control" name="publish_date" id="an_pub" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-6 mb-3"><label class="form-label">Expire Date</label><input type="date" class="form-control" name="expire_date" id="an_exp"><div class="form-text">Leave blank to keep it showing indefinitely.</div></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save Draft</button></div>
    </form>
</div></div></div>
<?php endif; ?>

<script>
// HTML-escape helper (page-local per app convention)
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }
const AN_CSRF = <?= json_encode(csrf_token()) ?>;
const AN_CAN_EDIT = <?= json_encode($can_edit) ?>, AN_CAN_DELETE = <?= json_encode($can_delete) ?>, AN_CAN_PUBLISH = <?= json_encode($can_publish) ?>;
let annTable = null, ANN = [];

function annStatusBadge(s) { const m={draft:['#e9ecef','#495057'],published:['#198754','#fff'],archived:['#6c757d','#fff']}; const [bg,fg]=m[s]||['#e9ecef','#495057']; return `<span class="badge" style="background:${bg};color:${fg}">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`; }
function audLabel(r) { if (r.audience_type==='department') return 'Dept: '+safeOutput(r.department_name||'—'); if (r.audience_type==='project') return 'Project: '+safeOutput(r.project_name||'—'); return 'Everyone'; }
function annActions(r) {
    let items = `<li><button class="dropdown-item py-2" onclick="viewAnn(${r.announcement_id})"><i class="bi bi-eye text-primary me-2"></i>View</button></li>`;
    if (AN_CAN_EDIT && r.status==='draft') items += `<li><button class="dropdown-item py-2" onclick="editAnn(${r.announcement_id})"><i class="bi bi-pencil text-primary me-2"></i>Edit</button></li>`;
    if (AN_CAN_PUBLISH && r.status==='draft') items += `<li><button class="dropdown-item py-2" onclick="annAction(${r.announcement_id},'publish')"><i class="bi bi-send text-primary me-2"></i>Publish</button></li>`;
    if (AN_CAN_PUBLISH && r.status==='published') items += `<li><button class="dropdown-item py-2" onclick="annAction(${r.announcement_id},'archive')"><i class="bi bi-archive text-secondary me-2"></i>Archive</button></li>`;
    if (AN_CAN_DELETE) items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 text-danger" onclick="annDelete(${r.announcement_id})"><i class="bi bi-trash text-danger me-2"></i>Delete</button></li>`;
    return `<div class="dropdown d-flex justify-content-end"><button class="btn btn-sm btn-outline-primary dropdown-toggle px-2" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button><ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
}
function loadAnn() {
    $.getJSON('<?= buildUrl('api/get_announcements.php') ?>', { mode:'manage', status:$('#af_status').val() }, function (res) {
        if (!res.success) { Swal.fire({icon:'error',title:'Error',text:res.message||'Could not load.'}); return; }
        ANN = res.data;
        $('#as_current').text(res.stats.published_current); $('#as_drafts').text(res.stats.drafts); $('#as_expiring').text(res.stats.expiring);
        const totalUsers = <?= (int)($pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn()) ?>;
        let readsSum=0, pubCount=0;
        res.data.forEach(r => { if (r.status==='published') { pubCount++; readsSum += Math.min(Number(r.read_count), totalUsers); } });
        $('#as_readrate').text(pubCount && totalUsers ? Math.round(readsSum/(pubCount*totalUsers)*100)+'%' : '—');
        annTable.clear().rows.add(res.data.map(r => [
            '', safeOutput(r.title), audLabel(r), safeOutput(r.publish_date), safeOutput(r.expire_date||'—'),
            r.read_count, annStatusBadge(r.status), annActions(r)
        ])).draw();
    });
}
function annCards(rows) {
    if (!rows.length) { $('#annCardView').html('<div class="col-12 text-center py-5 text-muted">No announcements yet.</div>'); return; }
    $('#annCardView').html(rows.map(r => `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
        <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(r.title)}</div>${annStatusBadge(r.status)}</div>
        <div class="small text-muted mt-1">${audLabel(r)} · ${safeOutput(r.publish_date)}</div>
        <button class="btn btn-sm btn-outline-primary mt-2" onclick="viewAnn(${r.announcement_id})"><i class="bi bi-eye"></i> View</button></div></div></div>`).join(''));
}
function viewAnn(id) { const r = ANN.find(x=>Number(x.announcement_id)===id); if (!r) return;
    $('#annViewBody').html(`<div class="fs-5 fw-bold">${safeOutput(r.title)}</div><div class="small text-muted mb-2">${audLabel(r)} · ${safeOutput(r.publish_date)}${r.expire_date?' → '+safeOutput(r.expire_date):''} · ${annStatusBadge(r.status)}</div><div style="white-space:pre-wrap">${safeOutput(r.body)}</div><div class="small text-muted mt-2">${r.read_count} read</div>`);
    new bootstrap.Modal(document.getElementById('annViewModal')).show();
}
function annAction(id, action) {
    Swal.fire({ title: action==='publish'?'Publish this announcement?':'Archive it?', text: action==='publish'?'Recipients will be notified.':'', icon:'question', showCancelButton:true, confirmButtonText:'Yes' })
        .then(r => { if (!r.isConfirmed) return;
            $.post('<?= buildUrl('api/manage_announcement.php') ?>', { action, announcement_id:id, _csrf:AN_CSRF }, function (res) {
                if (res.success) { loadAnn(); Swal.fire({icon:'success',title:'Done!',text:res.message,timer:2000,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:res.message});
            }, 'json'); });
}
function annDelete(id) { Swal.fire({title:'Delete announcement?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Delete'}).then(r=>{ if(r.isConfirmed) $.post('<?= buildUrl('api/manage_announcement.php') ?>',{action:'delete',announcement_id:id,_csrf:AN_CSRF},function(res){ if(res.success) loadAnn(); else Swal.fire({icon:'error',title:'Error',text:res.message}); },'json'); }); }

$(function () {
    annTable = $('#annTable').DataTable({ responsive:false, scrollX:true, pageLength:25, order:[[3,'desc']], dom:'rtip',
        columnDefs:[{targets:0,orderable:false,searchable:false,className:'text-center',render:(d,t,row,meta)=>meta.row+1+meta.settings._iDisplayStart}],
        language:{emptyTable:'No announcements yet.'}, drawCallback:function(){ annCards(this.api().rows({page:'current'})[0].map(i=>ANN[i]).filter(Boolean)); } });
    $('#af_status').on('change', loadAnn);
    function av(){ if (window.innerWidth<768){$('#annTableView').addClass('d-none');$('#annCardView').removeClass('d-none');}else{$('#annTableView').removeClass('d-none');$('#annCardView').addClass('d-none');} }
    av(); $(window).on('resize', av);
    loadAnn();
});

<?php if ($can_create): ?>
window.openAnnModal = function () { $('#annForm')[0].reset(); $('#an_id').val(''); $('#annModalTitle').text('New Announcement'); $('#an_audience').trigger('change'); new bootstrap.Modal(document.getElementById('annModal')).show(); };
window.editAnn = function (id) { const r = ANN.find(x=>Number(x.announcement_id)===id); if (!r) return;
    $('#annModalTitle').text('Edit Announcement'); $('#an_id').val(id); $('#an_title').val(r.title); $('#an_body').val(r.body);
    $('#an_priority').val(r.priority); $('#an_audience').val(r.audience_type).trigger('change'); $('#an_dept').val(r.department_id||''); $('#an_proj').val(r.project_id||'');
    $('#an_pub').val(r.publish_date); $('#an_exp').val(r.expire_date||'');
    new bootstrap.Modal(document.getElementById('annModal')).show();
};
$('#an_audience').on('change', function () {
    $('#an_dept_wrap').toggle($(this).val()==='department');
    $('#an_proj_wrap').toggle($(this).val()==='project');
});
$('#annForm').on('submit', function (e) {
    e.preventDefault();
    const btn=$(this).find('[type="submit"]'); btn.prop('disabled',true);
    $.post('<?= buildUrl('api/manage_announcement.php') ?>', $(this).serialize()+'&action='+($('#an_id').val()?'edit':'add'), function (r) {
        if (r.success) { bootstrap.Modal.getInstance(document.getElementById('annModal')).hide(); loadAnn(); Swal.fire({icon:'success',title:'Saved!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json').fail(()=>Swal.fire({icon:'error',title:'Error',text:'Server error.'})).always(()=>btn.prop('disabled',false));
});
<?php endif; ?>
</script>

<?php includeFooter(); ?>
