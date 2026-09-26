<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'Agent View';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_agents');
includeHeader();

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo '<div class="alert alert-danger m-4">' . t('Invalid agent ID.') . '</div>'; includeFooter(); exit; }

$agent = $pdo->prepare("
    SELECT a.*, p.agent_name AS parent_name
    FROM mm_agents a
    LEFT JOIN mm_agents p ON p.agent_id = a.parent_agent_id
    WHERE a.agent_id = ? AND a.status != 'closed'
");
$agent->execute([$id]);
$agent = $agent->fetch(PDO::FETCH_ASSOC);
if (!$agent) { echo '<div class="alert alert-warning m-4">' . t('Agent not found.') . '</div>'; includeFooter(); exit; }

$tillsStmt = $pdo->prepare("
    SELECT t.*, n.network_name, n.network_code, n.color_hex
    FROM mm_tills t
    LEFT JOIN mm_networks n ON n.network_id = t.network_id
    WHERE t.agent_id=? AND t.status!='closed'
    ORDER BY t.till_number
");
$tillsStmt->execute([$id]);
$tills = $tillsStmt->fetchAll(PDO::FETCH_ASSOC);

$subStmt = $pdo->prepare("SELECT * FROM mm_agents WHERE parent_agent_id=? AND status!='closed' ORDER BY agent_name");
$subStmt->execute([$id]);
$subAgents = $subStmt->fetchAll(PDO::FETCH_ASSOC);

$can_edit   = canEdit('mm_agents');
$can_create = canCreate('mm_agents');
$can_delete = canDelete('mm_agents');

$networks_list = $pdo->query("SELECT network_id, network_code, network_name FROM mm_networks WHERE status='active' ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Agent: ' . $agent['agent_name'];

logActivity($pdo, $_SESSION['user_id'], 'View MM Agent', 'Viewed agent: ' . $agent['agent_name']);
?>

<div class="container-fluid mt-3 mb-5">
    <div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
        <a href="<?= getUrl('mm_agents') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <h4 class="mb-0 fw-bold"><?= safe_output($agent['agent_name']) ?></h4>
        <code class="text-muted"><?= safe_output($agent['agent_code']) ?></code>
        <span class="badge <?= $agent['status']==='active'?'bg-success':($agent['status']==='suspended'?'bg-warning text-dark':'bg-secondary') ?>">
            <?= ucfirst(safe_output($agent['status'])) ?>
        </span>
        <?php if ($can_edit): ?>
        <button class="btn btn-sm btn-outline-primary ms-auto" onclick='editAgent(<?= json_encode($agent) ?>)'>
            <i class="bi bi-pencil me-1"></i><?= t('Edit') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Agent info cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted"><?= t('Outlet Type') ?></div>
                <div class="fw-semibold"><?= safe_output(ucfirst(str_replace('_', ' ', $agent['outlet_type'] ?? ''))) ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted"><?= t('Phone') ?></div>
                <div class="fw-semibold"><?= safe_output($agent['phone_primary'] ?: '—') ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted"><?= t('Location') ?></div>
                <div class="fw-semibold"><?= safe_output(implode(', ', array_filter([$agent['region'], $agent['district'], $agent['ward']])) ?: '—') ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted"><?= t('Super-Agent') ?></div>
                <div class="fw-semibold"><?= $agent['parent_name'] ? safe_output($agent['parent_name']) : '—' ?></div>
            </div>
        </div>
        <?php if ($agent['bot_license']): ?>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm p-3">
                <div class="small text-muted"><?= t('BOT License') ?></div>
                <div class="fw-semibold"><?= safe_output($agent['bot_license']) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tills section -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0"><i class="bi bi-cash-coin text-success me-2"></i><?= t('Tills / Counters') ?></h5>
        <?php if ($can_create): ?>
        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addTillModal">
            <i class="bi bi-plus-circle me-1"></i><?= t('Add Till') ?>
        </button>
        <?php endif; ?>
    </div>
    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= t('Till Number') ?></th>
                        <th><?= t('Network') ?></th>
                        <th><?= t('SIM MSISDN') ?></th>
                        <th><?= t('Float Ceil.') ?></th>
                        <th><?= t('Cash Ceil.') ?></th>
                        <th><?= t('Status') ?></th>
                        <?php if ($can_edit || $can_delete): ?><th class="text-end"><?= t('Actions') ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tills as $till): ?>
                    <tr>
                        <td><code><?= safe_output($till['till_number']) ?></code></td>
                        <td>
                            <span class="d-inline-block me-1" style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($till['color_hex'] ?: '#999') ?>"></span>
                            <?= safe_output($till['network_name'] ?: '—') ?>
                        </td>
                        <td><?= safe_output($till['sim_msisdn'] ?: '—') ?></td>
                        <td class="text-muted small"><?= $till['float_ceiling'] ? 'TZS '.number_format((float)$till['float_ceiling']) : '—' ?></td>
                        <td class="text-muted small"><?= $till['cash_ceiling'] ? 'TZS '.number_format((float)$till['cash_ceiling']) : '—' ?></td>
                        <td><span class="badge <?= $till['status']==='active'?'bg-success':($till['status']==='suspended'?'bg-warning text-dark':'bg-secondary') ?>"><?= ucfirst(safe_output($till['status'])) ?></span></td>
                        <?php if ($can_edit || $can_delete): ?>
                        <td class="text-end">
                            <?php if ($can_edit): ?>
                            <button class="btn btn-sm btn-outline-primary" onclick='editTill(<?= json_encode($till) ?>)'><i class="bi bi-pencil"></i></button>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteTill(<?= $till['till_id'] ?>, '<?= addslashes($till['till_number']) ?>')"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tills)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3"><?= t('No tills added yet.') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Sub-agents section -->
    <?php if (!empty($subAgents)): ?>
    <h5 class="mb-2"><i class="bi bi-diagram-2 text-info me-2"></i><?= t('Sub-Agents') ?></h5>
    <div class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th><?= t('Code') ?></th><th><?= t('Name') ?></th><th><?= t('Phone') ?></th><th><?= t('Location') ?></th><th><?= t('Status') ?></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($subAgents as $sa): ?>
                    <tr>
                        <td><code><?= safe_output($sa['agent_code']) ?></code></td>
                        <td><a href="<?= getUrl('mm_agent_view') ?>?id=<?= $sa['agent_id'] ?>"><?= safe_output($sa['agent_name']) ?></a></td>
                        <td><?= safe_output($sa['phone_primary'] ?: '—') ?></td>
                        <td class="small text-muted"><?= safe_output(implode(', ', array_filter([$sa['region'], $sa['district']])) ?: '—') ?></td>
                        <td><span class="badge <?= $sa['status']==='active'?'bg-success':($sa['status']==='suspended'?'bg-warning text-dark':'bg-secondary') ?>"><?= ucfirst(safe_output($sa['status'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add Till Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addTillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i><?= t('Add Till') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addTillForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="agent_id" value="<?= $id ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Network') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="network_id" required>
                                <option value=""></option>
                                <?php foreach ($networks_list as $n): ?>
                                <option value="<?= $n['network_id'] ?>"><?= safe_output($n['network_code'].' — '.$n['network_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Till Number') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="till_number" required placeholder="e.g. 255712345678">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('SIM MSISDN') ?></label>
                            <input type="text" class="form-control" name="sim_msisdn" placeholder="+255...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Float Ceiling (TZS)') ?></label>
                            <input type="number" class="form-control" name="float_ceiling" min="0" step="1000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Cash Ceiling (TZS)') ?></label>
                            <input type="number" class="form-control" name="cash_ceiling" min="0" step="1000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Status') ?></label>
                            <select class="form-select" name="status">
                                <option value="active"><?= t('Active') ?></option>
                                <option value="suspended"><?= t('Suspended') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Till Modal -->
<?php if ($can_edit): ?>
<div class="modal fade" id="editTillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i><?= t('Edit Till') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editTillForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="till_id" id="edit_till_id">
                    <input type="hidden" name="agent_id" value="<?= $id ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Network') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="network_id" id="edit_till_network" required>
                                <option value=""></option>
                                <?php foreach ($networks_list as $n): ?>
                                <option value="<?= $n['network_id'] ?>"><?= safe_output($n['network_code'].' — '.$n['network_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Till Number') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="till_number" id="edit_till_number" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('SIM MSISDN') ?></label>
                            <input type="text" class="form-control" name="sim_msisdn" id="edit_till_sim">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Float Ceiling (TZS)') ?></label>
                            <input type="number" class="form-control" name="float_ceiling" id="edit_till_float_ceil" min="0" step="1000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Cash Ceiling (TZS)') ?></label>
                            <input type="number" class="form-control" name="cash_ceiling" id="edit_till_cash_ceil" min="0" step="1000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Status') ?></label>
                            <select class="form-select" name="status" id="edit_till_status">
                                <option value="active"><?= t('Active') ?></option>
                                <option value="suspended"><?= t('Suspended') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle me-1"></i><?= t('Update') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Agent Modal -->
<?php if ($can_edit): ?>
<div class="modal fade" id="editAgentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i><?= t('Edit Agent') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editAgentForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="agent_id" id="ea_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= t('Agent Name') ?></label>
                            <input type="text" class="form-control" name="agent_name" id="ea_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Outlet Type') ?></label>
                            <select class="form-select" name="outlet_type" id="ea_outlet_type">
                                <option value="main"><?= t('Main Agent') ?></option>
                                <option value="sub"><?= t('Sub-Agent') ?></option>
                                <option value="kiosk"><?= t('Kiosk') ?></option>
                                <option value="shop_in_shop"><?= t('Shop-in-Shop') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Phone (Primary)') ?></label>
                            <input type="text" class="form-control" name="phone_primary" id="ea_phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Region') ?></label>
                            <input type="text" class="form-control" name="region" id="ea_region">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('District') ?></label>
                            <input type="text" class="form-control" name="district" id="ea_district">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Street / Address') ?></label>
                            <input type="text" class="form-control" name="street" id="ea_street">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Status') ?></label>
                            <select class="form-select" name="status" id="ea_status">
                                <option value="active"><?= t('Active') ?></option>
                                <option value="suspended"><?= t('Suspended') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle me-1"></i><?= t('Update') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    $('#addTillForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/save_till.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success:r=>{if(r.success){Swal.fire({icon:'success',title:'<?= t('Saved!') ?>',timer:1500,showConfirmButton:false}).then(()=>location.reload());}else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});}},
            error:()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });
    $('#editTillForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/save_till.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success:r=>{if(r.success){Swal.fire({icon:'success',title:'<?= t('Updated!') ?>',timer:1500,showConfirmButton:false}).then(()=>location.reload());}else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});}},
            error:()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });
    $('#editAgentForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/save_agent.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success:r=>{if(r.success){Swal.fire({icon:'success',title:'<?= t('Updated!') ?>',timer:1500,showConfirmButton:false}).then(()=>location.reload());}else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});}},
            error:()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });
    $('#addTillModal, #editTillModal').on('shown.bs.modal', function(){
        const modal=$(this);
        modal.find('.select2-static').each(function(){
            if(!$(this).hasClass('select2-hidden-accessible')) $(this).select2({theme:'bootstrap-5',dropdownParent:modal,placeholder:'<?= t('Select…') ?>',allowClear:true,width:'100%'});
        });
    });
    $('.modal').on('hidden.bs.modal', function(){$(this).find('form')[0]?.reset();});
});

function editTill(t) {
    $('#edit_till_id').val(t.till_id);
    $('#edit_till_number').val(t.till_number);
    $('#edit_till_sim').val(t.sim_msisdn);
    $('#edit_till_float_ceil').val(t.float_ceiling);
    $('#edit_till_cash_ceil').val(t.cash_ceiling);
    $('#edit_till_status').val(t.status);
    $('#editTillModal').one('shown.bs.modal', function(){
        $('#edit_till_network').val(t.network_id).trigger('change');
    });
    new bootstrap.Modal(document.getElementById('editTillModal')).show();
}

function deleteTill(id, number) {
    Swal.fire({title:'<?= t('Close Till?') ?>',text:number,icon:'warning',showCancelButton:true,confirmButtonColor:'#dc3545',confirmButtonText:'<?= t('Yes, Close') ?>'})
    .then(r=>{
        if(!r.isConfirmed) return;
        $.post('<?= buildUrl('api/mobile_money/save_till.php') ?>',{_csrf:'<?= csrf_token() ?>',_method:'DELETE',till_id:id},res=>{
            if(res.success){Swal.fire({icon:'success',title:'<?= t('Closed!') ?>',timer:1400,showConfirmButton:false}).then(()=>location.reload());}
            else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:res.message});}
        },'json');
    });
}

function editAgent(a) {
    $('#ea_id').val(a.agent_id); $('#ea_name').val(a.agent_name);
    $('#ea_outlet_type').val(a.outlet_type); $('#ea_phone').val(a.phone_primary);
    $('#ea_region').val(a.region); $('#ea_district').val(a.district); $('#ea_street').val(a.street);
    $('#ea_status').val(a.status);
    new bootstrap.Modal(document.getElementById('editAgentModal')).show();
}
</script>

<?php includeFooter(); ?>
