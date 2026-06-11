<?php
ob_start();
$page_title = 'Pipeline Stages';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('crm_pipeline');
includeHeader();

$can_create = canCreate('crm_pipeline');
$can_edit   = canEdit('crm_pipeline');
$can_delete = canDelete('crm_pipeline');

$stages = $pdo->query("
    SELECT ps.*, (SELECT COUNT(*) FROM crm_leads cl WHERE cl.pipeline_stage_id = ps.stage_id AND cl.status != 'deleted') AS lead_count
    FROM crm_pipeline_stages ps
    WHERE ps.status = 'active'
    ORDER BY ps.stage_order ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.stage-row { background:#fff; border-radius:8px; padding:12px 14px; margin-bottom:8px; box-shadow:0 1px 4px rgba(0,0,0,.07); display:flex; align-items:center; gap:12px; cursor:<?= $can_edit ? 'grab' : 'default' ?>; }
.stage-row:active { cursor:grabbing; }
.stage-dot  { width:18px; height:18px; border-radius:50%; flex-shrink:0; }
.stage-grip { color:#bbb; font-size:1.1rem; flex-shrink:0; }
.sortable-ghost { opacity:.4; background:#e9ecef !important; }
</style>

<div class="container-fluid mt-3 mb-5" style="max-width:780px">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-layers text-primary me-2"></i>Pipeline Stages</h4>
            <p class="text-muted small mb-0">Drag rows to reorder • Won and Lost stages cannot be deleted</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('crm/pipeline') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-kanban me-1"></i> Back to Board
            </a>
            <?php if ($can_create): ?>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStageModal">
                <i class="bi bi-plus-circle me-1"></i> Add Stage
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div id="stageList">
        <?php foreach ($stages as $s): ?>
        <div class="stage-row" data-id="<?= $s['stage_id'] ?>">
            <?php if ($can_edit): ?><i class="bi bi-grip-vertical stage-grip"></i><?php endif; ?>
            <span class="stage-dot" style="background:<?= htmlspecialchars($s['color']) ?>"></span>
            <div class="flex-grow-1">
                <span class="fw-semibold"><?= safe_output($s['stage_name']) ?></span>
                <?php if ($s['is_won']): ?><span class="badge bg-success ms-1" style="font-size:.68rem">Won</span><?php endif; ?>
                <?php if ($s['is_lost']): ?><span class="badge bg-danger ms-1" style="font-size:.68rem">Lost</span><?php endif; ?>
                <span class="text-muted small ms-2"><?= (int)$s['lead_count'] ?> lead<?= $s['lead_count'] != 1 ? 's' : '' ?></span>
            </div>
            <div class="d-flex gap-1">
                <?php if ($can_edit): ?>
                <button class="btn btn-sm btn-outline-primary" onclick="editStage(<?= $s['stage_id'] ?>, <?= htmlspecialchars(json_encode($s['stage_name'])) ?>, '<?= htmlspecialchars($s['color']) ?>', <?= $s['is_won'] ?>, <?= $s['is_lost'] ?>)">
                    <i class="bi bi-pencil"></i>
                </button>
                <?php endif; ?>
                <?php if ($can_delete && !$s['is_won'] && !$s['is_lost']): ?>
                <button class="btn btn-sm btn-outline-danger" onclick="deleteStage(<?= $s['stage_id'] ?>, <?= htmlspecialchars(json_encode($s['stage_name'])) ?>, <?= (int)$s['lead_count'] ?>)">
                    <i class="bi bi-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($stages)): ?>
        <div class="text-center text-muted py-5">No stages configured.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Stage Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addStageModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title mb-0"><i class="bi bi-plus-circle me-1"></i>Add Stage</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addStageForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="add">
                    <div id="add-msg" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Stage Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="stage_name" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Colour</label>
                        <input type="color" class="form-control form-control-sm form-control-color" name="color" value="#6c757d">
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="is_won" value="1" id="addIsWon">
                        <label class="form-check-label small" for="addIsWon">Mark as "Won" stage</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_lost" value="1" id="addIsLost">
                        <label class="form-check-label small" for="addIsLost">Mark as "Lost" stage</label>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Add Stage</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Stage Modal -->
<?php if ($can_edit): ?>
<div class="modal fade" id="editStageModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title mb-0"><i class="bi bi-pencil me-1"></i>Edit Stage</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editStageForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="stage_id" id="edit_stage_id">
                    <div id="edit-msg" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Stage Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="stage_name" id="edit_stage_name" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Colour</label>
                        <input type="color" class="form-control form-control-sm form-control-color" name="color" id="edit_color">
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="is_won" value="1" id="editIsWon">
                        <label class="form-check-label small" for="editIsWon">Mark as "Won" stage</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_lost" value="1" id="editIsLost">
                        <label class="form-check-label small" for="editIsLost">Mark as "Lost" stage</label>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
const MANAGE_URL = '<?= buildUrl('api/crm/manage_stage.php') ?>';
const CSRF = '<?= csrf_token() ?>';

<?php if ($can_edit): ?>
Sortable.create(document.getElementById('stageList'), {
    animation: 150,
    handle: '.stage-grip',
    ghostClass: 'sortable-ghost',
    onEnd: function() {
        const order = [...document.querySelectorAll('#stageList .stage-row')].map(el => el.dataset.id);
        $.post(MANAGE_URL, { action: 'reorder', 'order[]': order, _csrf: CSRF })
            .fail(() => Swal.fire({icon:'error', title:'Error', text:'Failed to save order'}));
    }
});
<?php endif; ?>

function editStage(id, name, color, isWon, isLost) {
    document.getElementById('edit_stage_id').value   = id;
    document.getElementById('edit_stage_name').value = name;
    document.getElementById('edit_color').value      = color;
    document.getElementById('editIsWon').checked     = !!isWon;
    document.getElementById('editIsLost').checked    = !!isLost;
    document.getElementById('edit-msg').innerHTML    = '';
    new bootstrap.Modal(document.getElementById('editStageModal')).show();
}

function deleteStage(id, name, leadCount) {
    const txt = leadCount > 0
        ? `"${name}" has ${leadCount} lead(s). Move them to another stage first.`
        : `Delete stage "${name}"? This cannot be undone.`;
    if (leadCount > 0) { Swal.fire({icon:'warning', title:'Cannot delete', text: txt}); return; }
    Swal.fire({icon:'warning', title:'Delete stage?', text: txt, showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, delete'})
        .then(r => {
            if (!r.isConfirmed) return;
            $.post(MANAGE_URL, {action:'delete', stage_id:id, _csrf:CSRF}, null, 'json')
                .done(res => {
                    if (res.success) { Swal.fire({icon:'success',title:'Deleted',text:res.message,timer:1800,showConfirmButton:false}).then(()=>location.reload()); }
                    else             { Swal.fire({icon:'error',title:'Error',text:res.message}); }
                })
                .fail(() => Swal.fire({icon:'error',title:'Error',text:'Server error'}));
        });
}

$('#addStageForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]'), orig = btn.html();
    btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({url:MANAGE_URL,type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
        success: res => {
            if (res.success) { Swal.fire({icon:'success',title:'Done',text:res.message,timer:1600,showConfirmButton:false}).then(()=>location.reload()); }
            else { $('#add-msg').html('<div class="alert alert-danger py-1 px-2 small">'+res.message+'</div>'); }
        },
        error: () => { $('#add-msg').html('<div class="alert alert-danger py-1 px-2 small">Server error</div>'); },
        complete: () => btn.prop('disabled',false).html(orig)
    });
});

$('#editStageForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $(this).find('[type=submit]'), orig = btn.html();
    btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({url:MANAGE_URL,type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
        success: res => {
            if (res.success) { Swal.fire({icon:'success',title:'Done',text:res.message,timer:1600,showConfirmButton:false}).then(()=>location.reload()); }
            else { $('#edit-msg').html('<div class="alert alert-danger py-1 px-2 small">'+res.message+'</div>'); }
        },
        error: () => { $('#edit-msg').html('<div class="alert alert-danger py-1 px-2 small">Server error</div>'); },
        complete: () => btn.prop('disabled',false).html(orig)
    });
});
</script>

<?php includeFooter(); ob_end_flush(); ?>
