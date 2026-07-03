<?php
// HR Checklists — Tier 4, Phase 4.4 (D30). page_key: hr_checklists.
// Two tabs: Templates (canEdit) and Active checklists. Spawned checklists
// snapshot template item text so editing a template never rewrites in-flight ones.
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('hr_checklists');
includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View checklists', 'User viewed the HR Checklists page');

$can_create = canCreate('hr_checklists');
$can_edit   = canEdit('hr_checklists');
?>

<div class="container-fluid mt-4">
    <h4 class="mb-3"><i class="bi bi-check2-square me-2 text-primary"></i>Onboarding &amp; Offboarding Checklists</h4>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-active" type="button">Active Checklists</button></li>
        <?php if ($can_edit): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-templates" type="button">Templates</button></li><?php endif; ?>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="pane-active" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <select class="form-select form-select-sm" id="cf_status" style="max-width:200px"><option value="">All statuses</option><option value="in_progress">In progress</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select>
                <?php if ($can_create): ?><button class="btn btn-sm btn-primary" onclick="openSpawnModal()"><i class="bi bi-plus-circle me-1"></i> Spawn Checklist</button><?php endif; ?>
            </div>
            <div id="activeList" class="row g-3"><div class="col-12 text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span></div></div>
        </div>

        <?php if ($can_edit): ?>
        <div class="tab-pane fade" id="pane-templates" role="tabpanel">
            <div class="d-flex justify-content-end mb-3"><button class="btn btn-sm btn-primary" onclick="openTemplateModal()"><i class="bi bi-plus-circle me-1"></i> New Template</button></div>
            <div id="templateList" class="row g-3"><div class="col-12 text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span></div></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Checklist detail modal -->
<div class="modal fade" id="checklistModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-check2-square me-1"></i> Checklist</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="checklistBody"></div>
</div></div></div>

<?php if ($can_create): ?>
<div class="modal fade" id="spawnModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title">Spawn Checklist</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <form id="spawnForm"><div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="mb-3"><label class="form-label">Employee <span class="text-danger">*</span></label><select class="form-select" name="employee_id" id="sp_employee" required></select></div>
        <div class="mb-3"><label class="form-label">Template <span class="text-danger">*</span></label><select class="form-select" name="template_id" id="sp_template" required></select></div>
    </div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Create</button></div></form>
</div></div></div>
<?php endif; ?>

<?php if ($can_edit): ?>
<div class="modal fade" id="templateModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="tplModalTitle">New Template</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" id="tpl_id">
        <div class="row mb-3">
            <div class="col-md-8"><label class="form-label">Name</label><input class="form-control" id="tpl_name"></div>
            <div class="col-md-4"><label class="form-label">Type</label><select class="form-select" id="tpl_type"><option value="onboarding">Onboarding</option><option value="offboarding">Offboarding</option></select></div>
        </div>
        <button class="btn btn-sm btn-primary mb-3" onclick="saveTemplate()"><i class="bi bi-check-circle me-1"></i> Save Template</button>
        <div id="tpl_items_wrap" style="display:none">
            <hr><h6>Items</h6>
            <div id="tpl_items"></div>
            <div class="input-group input-group-sm mt-2"><input class="form-control" id="tpl_new_item" placeholder="New item text"><button class="btn btn-outline-primary" onclick="addItem()"><i class="bi bi-plus"></i> Add</button></div>
        </div>
    </div>
</div></div></div>
<?php endif; ?>

<script>
const CL_CSRF = <?= json_encode(csrf_token()) ?>;
const CL_CAN_EDIT = <?= json_encode($can_edit) ?>, CL_CAN_CREATE = <?= json_encode($can_create) ?>;
let TEMPLATES = [], TPL_ITEMS = [];

function typeBadge(t){ return `<span class="badge bg-${t==='onboarding'?'success':'secondary'}">${t.charAt(0).toUpperCase()+t.slice(1)}</span>`; }
function clStatusBadge(s){ const m={in_progress:['#0d6efd','#fff'],completed:['#198754','#fff'],cancelled:['#6c757d','#fff']}; const [bg,fg]=m[s]||['#e9ecef','#495057']; return `<span class="badge" style="background:${bg};color:${fg}">${s.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase())}</span>`; }

function loadActive(){
    $.getJSON('<?= buildUrl('api/get_checklists.php') ?>', { mode:'active', status:$('#cf_status').val() }, function(res){
        if (!res.success){ $('#activeList').html('<div class="col-12 text-danger">Could not load.</div>'); return; }
        if (!res.data.length){ $('#activeList').html('<div class="col-12 text-center text-muted py-4">No checklists yet.</div>'); return; }
        $('#activeList').html(res.data.map(c=>{
            const pct = c.total>0 ? Math.round(c.done/c.total*100) : 0;
            return `<div class="col-md-6 col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(c.first_name+' '+c.last_name)}</div>${clStatusBadge(c.status)}</div>
                <div class="small text-muted mb-2">${typeBadge(c.checklist_type)}</div>
                <div class="progress mb-2" style="height:14px"><div class="progress-bar ${pct===100?'bg-success':'bg-primary'}" style="width:${pct}%">${c.done}/${c.total}</div></div>
                <button class="btn btn-sm btn-outline-primary" onclick="openChecklist(${c.checklist_id})"><i class="bi bi-list-check"></i> Open</button></div></div></div>`;
        }).join(''));
    });
}
function openChecklist(id){
    $.getJSON('<?= buildUrl('api/get_checklists.php') ?>', { mode:'checklist', checklist_id:id }, function(res){
        if (!res.success){ Swal.fire({icon:'error',title:'Error',text:res.message}); return; }
        const c=res.data;
        const editable = CL_CAN_EDIT && c.status==='in_progress';
        let rows = res.items.map(i=>`<tr>
            <td style="width:36px">${editable?`<input type="checkbox" class="cl-chk" data-id="${i.item_id}" ${Number(i.is_done)?'checked':''}>`:(Number(i.is_done)?'<i class="bi bi-check-circle-fill text-success"></i>':'<i class="bi bi-circle text-muted"></i>')}</td>
            <td>${safeOutput(i.item_text)}${i.done_by_name?`<br><small class="text-muted">by ${safeOutput(i.done_by_name)}${i.notes?' — '+safeOutput(i.notes):''}</small>`:''}</td></tr>`).join('');
        const done = res.items.filter(i=>Number(i.is_done)).length;
        $('#checklistBody').html(`
            <div class="d-flex justify-content-between mb-2"><div><div class="fs-5 fw-bold">${safeOutput(c.first_name+' '+c.last_name)}</div><div class="small text-muted">${typeBadge(c.checklist_type)} ${clStatusBadge(c.status)}</div></div>
                <div class="text-end small text-muted">${done}/${res.items.length} done</div></div>
            <table class="table table-sm align-middle"><tbody>${rows}</tbody></table>
            ${editable ? `<div class="d-flex gap-2"><button class="btn btn-sm btn-success" onclick="finishChecklist(${c.checklist_id},'completed')"><i class="bi bi-check2-all me-1"></i>Complete</button><button class="btn btn-sm btn-outline-danger" onclick="finishChecklist(${c.checklist_id},'cancelled')"><i class="bi bi-x-circle me-1"></i>Cancel</button></div>` : ''}`);
        new bootstrap.Modal(document.getElementById('checklistModal')).show();
        if (editable) $('.cl-chk').on('change', function(){
            const iid=$(this).data('id'), done=this.checked?1:0;
            $.post('<?= buildUrl('api/tick_checklist_item.php') ?>', { item_id:iid, is_done:done, _csrf:CL_CSRF }, function(r){ if(!r.success){ Swal.fire({icon:'error',title:'Error',text:r.message}); } else { loadActive(); } }, 'json');
        });
    });
}
window.openChecklist=openChecklist;
window.finishChecklist=function(id,status){
    Swal.fire({ title: status==='completed'?'Complete checklist?':'Cancel checklist?', icon: status==='completed'?'question':'warning', showCancelButton:true, confirmButtonColor: status==='cancelled'?'#dc3545':'#0d6efd', confirmButtonText:'Yes' }).then(res=>{
        if (!res.isConfirmed) return;
        $.post('<?= buildUrl('api/change_checklist_status.php') ?>', { checklist_id:id, status, _csrf:CL_CSRF }, function(r){
            if (r.success){ bootstrap.Modal.getInstance(document.getElementById('checklistModal')).hide(); loadActive(); Swal.fire({icon:'success',title:'Done!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message});
        }, 'json');
    });
};

$(function(){
    $('#cf_status').on('change', loadActive);
    loadActive();
    <?php if ($can_edit): ?>loadTemplates();<?php endif; ?>
});

<?php if ($can_create): ?>
window.openSpawnModal=function(){
    $('#spawnForm')[0].reset();
    // template options
    $.getJSON('<?= buildUrl('api/get_checklists.php') ?>', { mode:'templates' }, function(res){
        if (res.success) $('#sp_template').html(res.templates.filter(t=>t.status==='active').map(t=>`<option value="${t.template_id}">${safeOutput(t.template_name)} (${t.template_type})</option>`).join(''));
    });
    new bootstrap.Modal(document.getElementById('spawnModal')).show();
};
$('#spawnModal').on('shown.bs.modal', function(){
    if (!$('#sp_employee').hasClass('select2-hidden-accessible')) $('#sp_employee').select2({ theme:'bootstrap-5',dropdownParent:$('#spawnModal'),placeholder:'Select employee…',width:'100%',minimumInputLength:1,ajax:{url:'<?= buildUrl('api/account/search_employees.php') ?>',dataType:'json',delay:300,data:p=>({q:p.term}),processResults:d=>({results:d.results}),cache:true} });
});
$('#spawnForm').on('submit', function(e){
    e.preventDefault();
    $.post('<?= buildUrl('api/spawn_checklist.php') ?>', $(this).serialize(), function(r){
        if (r.success){ bootstrap.Modal.getInstance(document.getElementById('spawnModal')).hide(); loadActive(); Swal.fire({icon:'success',title:'Created!',text:r.message,timer:1600,showConfirmButton:false}); } else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json');
});
<?php endif; ?>

<?php if ($can_edit): ?>
function loadTemplates(){
    $.getJSON('<?= buildUrl('api/get_checklists.php') ?>', { mode:'templates' }, function(res){
        if (!res.success) return;
        TEMPLATES=res.templates; TPL_ITEMS=res.items;
        $('#templateList').html(res.templates.map(t=>{
            const items = res.items.filter(i=>Number(i.template_id)===Number(t.template_id));
            return `<div class="col-md-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(t.template_name)} ${typeBadge(t.template_type)}</div>${Number(t.is_default)?'<span class="badge bg-warning text-dark">Default</span>':''}</div>
                <ul class="small text-muted mt-2 mb-2">${items.map(i=>`<li>${safeOutput(i.item_text)}</li>`).join('')||'<li>No items</li>'}</ul>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="editTemplate(${t.template_id})"><i class="bi bi-pencil"></i> Edit</button>
                    ${Number(t.is_default)?'':`<button class="btn btn-outline-warning" onclick="tplAction('set_default',${t.template_id})"><i class="bi bi-star"></i> Default</button>`}
                    <button class="btn btn-outline-danger" onclick="tplAction('delete_template',${t.template_id})"><i class="bi bi-trash"></i></button>
                </div></div></div></div>`;
        }).join('') || '<div class="col-12 text-center text-muted py-4">No templates yet.</div>');
    });
}
function tplAction(action, id){
    const confirm = action==='delete_template';
    const go = () => $.post('<?= buildUrl('api/manage_checklist_template.php') ?>', { action, template_id:id, _csrf:CL_CSRF }, function(r){ if (r.success){ loadTemplates(); } else Swal.fire({icon:'error',title:'Error',text:r.message}); }, 'json');
    if (confirm) Swal.fire({title:'Delete template?',icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'Delete'}).then(r=>{ if(r.isConfirmed) go(); });
    else go();
}
window.tplAction=tplAction;
window.openTemplateModal=function(){ $('#tpl_id').val(''); $('#tpl_name').val(''); $('#tpl_type').val('onboarding'); $('#tpl_items_wrap').hide(); $('#tplModalTitle').text('New Template'); new bootstrap.Modal(document.getElementById('templateModal')).show(); };
window.editTemplate=function(id){
    const t=TEMPLATES.find(x=>Number(x.template_id)===id); if (!t) return;
    $('#tpl_id').val(id); $('#tpl_name').val(t.template_name); $('#tpl_type').val(t.template_type); $('#tplModalTitle').text('Edit Template');
    renderTplItems(id); $('#tpl_items_wrap').show();
    new bootstrap.Modal(document.getElementById('templateModal')).show();
};
function renderTplItems(tid){
    const items = TPL_ITEMS.filter(i=>Number(i.template_id)===Number(tid));
    $('#tpl_items').html(items.map(i=>`<div class="d-flex justify-content-between align-items-center py-1 border-bottom"><span>${safeOutput(i.item_text)}</span><button class="btn btn-sm btn-link text-danger p-0" onclick="delItem(${i.item_id})"><i class="bi bi-trash"></i></button></div>`).join('')||'<div class="small text-muted">No items yet.</div>');
}
window.saveTemplate=function(){
    const id=$('#tpl_id').val();
    const action = id ? 'rename_template' : 'add_template';
    $.post('<?= buildUrl('api/manage_checklist_template.php') ?>', { action, template_id:id, template_name:$('#tpl_name').val(), template_type:$('#tpl_type').val(), _csrf:CL_CSRF }, function(r){
        if (!r.success){ Swal.fire({icon:'error',title:'Error',text:r.message}); return; }
        if (!id && r.template_id){ $('#tpl_id').val(r.template_id); $('#tpl_items_wrap').show(); TEMPLATES.push({template_id:r.template_id,template_name:$('#tpl_name').val(),template_type:$('#tpl_type').val(),is_default:0,status:'active'}); }
        loadTemplates();
        Swal.fire({icon:'success',title:'Saved!',timer:1200,showConfirmButton:false});
    }, 'json');
};
window.addItem=function(){
    const tid=$('#tpl_id').val(), text=$('#tpl_new_item').val().trim(); if (!tid||!text) return;
    $.post('<?= buildUrl('api/manage_checklist_template.php') ?>', { action:'add_item', template_id:tid, item_text:text, sort_order:0, _csrf:CL_CSRF }, function(r){
        if (r.success){ TPL_ITEMS.push({item_id:r.item_id,template_id:tid,item_text:text,sort_order:0}); renderTplItems(tid); $('#tpl_new_item').val(''); loadTemplates(); } else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json');
};
window.delItem=function(iid){
    $.post('<?= buildUrl('api/manage_checklist_template.php') ?>', { action:'delete_item', item_id:iid, _csrf:CL_CSRF }, function(r){
        if (r.success){ TPL_ITEMS=TPL_ITEMS.filter(i=>Number(i.item_id)!==Number(iid)); renderTplItems($('#tpl_id').val()); loadTemplates(); } else Swal.fire({icon:'error',title:'Error',text:r.message});
    }, 'json');
};
<?php endif; ?>
</script>

<?php includeFooter(); ?>
