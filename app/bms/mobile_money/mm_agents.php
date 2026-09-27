<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'MM Agents';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_agents');
includeHeader();

$can_create = canCreate('mm_agents');
$can_edit   = canEdit('mm_agents');
$can_delete = canDelete('mm_agents');

$agents = $pdo->query("
    SELECT a.*,
           p.agent_name AS parent_name,
           (SELECT COUNT(*) FROM mm_tills t WHERE t.agent_id = a.agent_id AND t.status = 'active') AS till_count,
           (SELECT GROUP_CONCAT(DISTINCT n.network_name ORDER BY n.sort_order SEPARATOR ', ')
            FROM mm_tills t2 JOIN mm_networks n ON n.network_id = t2.network_id
            WHERE t2.agent_id = a.agent_id AND t2.status = 'active') AS networks_str
    FROM mm_agents a
    LEFT JOIN mm_agents p ON p.agent_id = a.parent_agent_id
    WHERE a.status != 'closed'
    ORDER BY a.agent_name
")->fetchAll(PDO::FETCH_ASSOC);

$parentAgents = $pdo->query("SELECT agent_id, agent_name, agent_code FROM mm_agents WHERE status='active' ORDER BY agent_name")->fetchAll(PDO::FETCH_ASSOC);

$total    = count($agents);
$active   = count(array_filter($agents, fn($a) => $a['status'] === 'active'));
$suspended = count(array_filter($agents, fn($a) => $a['status'] === 'suspended'));

logActivity($pdo, $_SESSION['user_id'], 'View MM Agents', 'Viewed Mobile Money agents list');
?>

<style>
.mm-stat-card{background:#d1e7dd!important;border-color:#badbcc!important;border-radius:12px;transition:transform .2s}
.mm-stat-card:hover{transform:translateY(-3px)}
.mm-stat-card .fw-bold,.mm-stat-card .fs-3,.mm-stat-card .fs-4,.mm-stat-card .fs-5{color:#0f5132!important}
.mm-stat-card .text-muted,.mm-stat-card .small{color:#0f5132!important;opacity:.85}
.mm-thead th{background:#fff!important;color:#212529;border-bottom:2px solid #dee2e6!important;text-align:center;font-weight:600;font-size:.8rem;padding:10px 8px}
.mm-sno{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#f0f2f5;color:#6b7280;font-size:.7rem;font-weight:700;flex-shrink:0}
.mm-kv{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid #f3f4f6;font-size:.82rem}
.mm-kv:last-child{border:0}
.mm-kv .kv-lbl{color:#9ca3af}
.mm-kv .kv-val{font-weight:500;text-align:right;max-width:65%;word-break:break-word}
.mm-card-foot{display:flex;gap:6px;padding:8px 12px;border-top:1px solid #f3f4f6}
.mm-card-foot .btn{flex:1;font-size:.78rem;padding:3px 6px}
</style>
<div class="container-fluid mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-shop-window text-primary me-2"></i><?= t('Agent Outlets') ?></h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle me-1"></i> <?= t('Add Agent') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-primary"><?= $total ?></div>
                <div class="small text-muted"><?= t('Total Agents') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-success"><?= $active ?></div>
                <div class="small text-muted"><?= t('Active') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-warning"><?= $suspended ?></div>
                <div class="small text-muted"><?= t('Suspended') ?></div>
            </div>
        </div>
    </div>

    <!-- Desktop table -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="agentsTable" class="table table-hover align-middle mb-0 w-100">
                        <thead class="mm-thead">
                            <tr>
                                <th class="text-center" style="width:48px"><?= t('S/No') ?></th>
                                <th class="text-center"><?= t('Agent Code') ?></th>
                                <th class="text-center"><?= t('Agent Name') ?></th>
                                <th class="text-center"><?= t('Type') ?></th>
                                <th class="text-center"><?= t('Networks (Tills)') ?></th>
                                <th class="text-center"><?= t('Phone') ?></th>
                                <th class="text-center"><?= t('Region') ?></th>
                                <th class="text-center"><?= t('Super-Agent') ?></th>
                                <th class="text-center"><?= t('Tills') ?></th>
                                <th class="text-center"><?= t('Status') ?></th>
                                <th class="text-center"><?= t('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sno = 1; foreach ($agents as $a): ?>
                            <tr data-id="<?= (int)$a['agent_id'] ?>" data-name="<?= htmlspecialchars($a['agent_name']) ?>" data-code="<?= htmlspecialchars($a['agent_code'] ?: '') ?>" data-outlet-type="<?= htmlspecialchars($a['outlet_type'] ?: '') ?>" data-phone="<?= htmlspecialchars($a['phone_primary'] ?: '') ?>" data-region="<?= htmlspecialchars($a['region'] ?: '') ?>" data-district="<?= htmlspecialchars($a['district'] ?: '') ?>" data-street="<?= htmlspecialchars($a['street'] ?: '') ?>" data-bot-license="<?= htmlspecialchars($a['bot_license'] ?: '') ?>" data-status="<?= htmlspecialchars($a['status']) ?>" data-networks="<?= htmlspecialchars($a['networks_str'] ?: '—') ?>" data-can-edit="<?= $can_edit ? '1' : '0' ?>">
                                <td class="text-center text-muted small"><?= $sno++ ?></td>
                                <td><code><?= safe_output($a['agent_code']) ?></code></td>
                                <td class="fw-semibold"><?= safe_output($a['agent_name']) ?></td>
                                <td><span class="badge bg-light text-dark"><?= ucfirst(str_replace('_', ' ', $a['outlet_type'])) ?></span></td>
                                <td class="small text-muted"><?= safe_output($a['networks_str'] ?: '—') ?></td>
                                <td><?= safe_output($a['phone_primary'] ?: '—') ?></td>
                                <td class="small text-muted"><?= safe_output(implode(', ', array_filter([$a['region'], $a['district']])) ?: '—') ?></td>
                                <td class="text-muted small"><?= $a['parent_name'] ? safe_output($a['parent_name']) : '—' ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?= (int)$a['till_count'] ?></span></td>
                                <td>
                                    <span class="badge <?= $a['status']==='active'?'bg-success':($a['status']==='suspended'?'bg-warning text-dark':'bg-secondary') ?>">
                                        <?= ucfirst(safe_output($a['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:130px;font-size:.85rem">
                                            <li><a class="dropdown-item" href="<?= getUrl('mm_agent_view') ?>?id=<?= $a['agent_id'] ?>"><i class="bi bi-eye me-2 text-info"></i><?= t('View') ?></a></li>
                                            <?php if ($can_edit): ?>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li><a class="dropdown-item" href="#" onclick="editAgent(window.__mmAgentData[<?= (int)$a['agent_id'] ?>]);return false"><i class="bi bi-pencil me-2 text-warning"></i><?= t('Edit') ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Add Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> <?= t('Add Agent Outlet') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="add-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= t('Agent Name') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="agent_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Outlet Type') ?></label>
                            <select class="form-select" name="outlet_type">
                                <option value="main"><?= t('Main Agent') ?></option>
                                <option value="sub"><?= t('Sub-Agent') ?></option>
                                <option value="kiosk"><?= t('Kiosk') ?></option>
                                <option value="shop_in_shop"><?= t('Shop-in-Shop') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Phone (Primary)') ?></label>
                            <input type="text" class="form-control" name="phone_primary" placeholder="+255...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Region') ?></label>
                            <input type="text" class="form-control" name="region">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('District') ?></label>
                            <input type="text" class="form-control" name="district">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Street / Address') ?></label>
                            <input type="text" class="form-control" name="street">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('BOT License No.') ?></label>
                            <input type="text" class="form-control" name="bot_license">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Super-Agent (if sub-agent)') ?></label>
                            <select class="form-select select2-static" name="parent_agent_id">
                                <option value=""></option>
                                <?php foreach ($parentAgents as $p): ?>
                                <option value="<?= $p['agent_id'] ?>"><?= safe_output($p['agent_code'].' — '.$p['agent_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
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
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> <?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Modal -->
<?php if ($can_edit): ?>
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> <?= t('Edit Agent Outlet') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="agent_id" id="edit_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="edit-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= t('Agent Name') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="agent_name" id="edit_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Outlet Type') ?></label>
                            <select class="form-select" name="outlet_type" id="edit_outlet_type">
                                <option value="main"><?= t('Main Agent') ?></option>
                                <option value="sub"><?= t('Sub-Agent') ?></option>
                                <option value="kiosk"><?= t('Kiosk') ?></option>
                                <option value="shop_in_shop"><?= t('Shop-in-Shop') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Phone (Primary)') ?></label>
                            <input type="text" class="form-control" name="phone_primary" id="edit_phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Region') ?></label>
                            <input type="text" class="form-control" name="region" id="edit_region">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('District') ?></label>
                            <input type="text" class="form-control" name="district" id="edit_district">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Street / Address') ?></label>
                            <input type="text" class="form-control" name="street" id="edit_street">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('BOT License No.') ?></label>
                            <input type="text" class="form-control" name="bot_license" id="edit_bot_license">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Status') ?></label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="active"><?= t('Active') ?></option>
                                <option value="suspended"><?= t('Suspended') ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle me-1"></i> <?= t('Update') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#agentsTable')) {
        $('#agentsTable').DataTable({
            responsive:false, scrollX:true, pageLength:25, order:[[2,'asc']], columnDefs:[{orderable:false,targets:0}], dom:'rtipB',
            language: { emptyTable: '<?= addslashes(t('No agents found. Add your first agent outlet.')) ?>' },
            buttons:[{extend:'excelHtml5',className:'d-none',exportOptions:{columns:':not(:last-child)'}}],
            drawCallback: function(){ renderCards(this.api().rows({page:'current'}).nodes()); }
        });
    }
    function applyView() {
        if(window.innerWidth<768){$('#tableView').addClass('d-none');$('#cardView').removeClass('d-none');}
        else{$('#tableView').removeClass('d-none');$('#cardView').addClass('d-none');}
    }
    applyView(); $(window).on('resize',applyView);

    $('#addModal, #editModal').on('shown.bs.modal', function(){
        const modal=$(this);
        modal.find('.select2-static').each(function(){
            if(!$(this).hasClass('select2-hidden-accessible'))
                $(this).select2({theme:'bootstrap-5',dropdownParent:modal,placeholder:'<?= t('Select…') ?>',allowClear:true,width:'100%'});
        });
    });

    $('#addForm').on('submit', function(e){
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/save_agent.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success:r=>{if(r.success){Swal.fire({icon:'success',title:'<?= t('Saved!') ?>',timer:1500,showConfirmButton:false}).then(()=>location.reload());}else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});}},
            error:()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });

    $('#editForm').on('submit', function(e){
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/save_agent.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success:r=>{if(r.success){Swal.fire({icon:'success',title:'<?= t('Updated!') ?>',timer:1500,showConfirmButton:false}).then(()=>location.reload());}else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});}},
            error:()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });

    $('.modal').on('hidden.bs.modal', function(){$(this).find('form')[0]?.reset();});
});

function editAgent(a) {
    $('#edit_id').val(a.agent_id); $('#edit_name').val(a.agent_name);
    $('#edit_outlet_type').val(a.outlet_type); $('#edit_phone').val(a.phone_primary);
    $('#edit_region').val(a.region); $('#edit_district').val(a.district);
    $('#edit_street').val(a.street); $('#edit_bot_license').val(a.bot_license);
    $('#edit_status').val(a.status);
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function renderCards(nodes) {
    if (!nodes.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No agents found') ?></div>'); return; }
    window.__mmAgentData = window.__mmAgentData || {};
    let html = '';
    $(nodes).each(function (idx) {
        const $tr = $(this);
        const id = $tr.data('id'), sno = idx + 1;
        const name = $tr.data('name'), code = $tr.data('code');
        const outletType = $tr.data('outlet-type'), phone = $tr.data('phone');
        const region = $tr.data('region'), district = $tr.data('district');
        const networks = $tr.data('networks'), status = $tr.data('status');
        const canEdit = $tr.data('can-edit') == 1;
        window.__mmAgentData[id] = { agent_id: id, agent_name: name, outlet_type: outletType, phone_primary: phone, region: region, district: district, street: $tr.data('street'), bot_license: $tr.data('bot-license'), status: status };
        const sBadge = status === 'active' ? 'bg-success' : (status === 'suspended' ? 'bg-warning text-dark' : 'bg-secondary');
        const loc = [region, district].filter(Boolean).join(', ') || '—';
        html += `<div class="col-12"><div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
          <div class="card-body p-3 pb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="mm-sno">${sno}</span>
              <span class="badge ${sBadge}" style="font-size:.73rem">${safeOutput(status.charAt(0).toUpperCase()+status.slice(1))}</span>
            </div>
            <div class="fw-semibold mb-2" style="font-size:.95rem">${safeOutput(name)}</div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Code') ?></span><span class="kv-val"><code>${safeOutput(code)}</code></span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Type') ?></span><span class="kv-val">${safeOutput(outletType)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Networks') ?></span><span class="kv-val">${safeOutput(networks)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Phone') ?></span><span class="kv-val">${safeOutput(phone||'—')}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Location') ?></span><span class="kv-val">${safeOutput(loc)}</span></div>
          </div>
          <div class="mm-card-foot">
            <a href="<?= getUrl('mm_agent_view') ?>?id=${id}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye me-1"></i><?= t('View') ?></a>
            ${canEdit ? `<button class="btn btn-sm btn-outline-primary" onclick="editAgent(window.__mmAgentData[${id}])"><i class="bi bi-pencil me-1"></i><?= t('Edit') ?></button>` : ''}
          </div>
        </div></div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
