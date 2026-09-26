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
           n.network_name, n.color_hex AS network_color, n.network_code,
           p.agent_name AS parent_name,
           (SELECT COUNT(*) FROM mm_tills t WHERE t.agent_id = a.agent_id AND t.status != 'deleted') AS till_count
    FROM mm_agents a
    LEFT JOIN mm_networks n ON n.network_id = a.network_id
    LEFT JOIN mm_agents  p ON p.agent_id   = a.parent_agent_id
    WHERE a.status != 'deleted'
    ORDER BY a.agent_name
")->fetchAll(PDO::FETCH_ASSOC);

$networks = $pdo->query("SELECT network_id, network_code, network_name, color_hex FROM mm_networks WHERE status='active' ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$parentAgents = $pdo->query("SELECT agent_id, agent_name, agent_code FROM mm_agents WHERE status='active' ORDER BY agent_name")->fetchAll(PDO::FETCH_ASSOC);

$total   = count($agents);
$active  = count(array_filter($agents, fn($a) => $a['status'] === 'active'));
$inactive = $total - $active;

logActivity($pdo, $_SESSION['user_id'], 'View MM Agents', 'Viewed Mobile Money agents list');
?>

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
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= $total ?></div>
                <div class="small text-muted"><?= t('Total Agents') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success"><?= $active ?></div>
                <div class="small text-muted"><?= t('Active') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-secondary"><?= $inactive ?></div>
                <div class="small text-muted"><?= t('Inactive') ?></div>
            </div>
        </div>
    </div>

    <!-- Desktop table -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="agentsTable" class="table table-hover align-middle mb-0 w-100">
                        <thead class="table-dark">
                            <tr>
                                <th><?= t('Agent Code') ?></th>
                                <th><?= t('Agent Name') ?></th>
                                <th><?= t('Network') ?></th>
                                <th><?= t('Phone') ?></th>
                                <th><?= t('Location') ?></th>
                                <th><?= t('Super-Agent') ?></th>
                                <th class="text-center"><?= t('Tills') ?></th>
                                <th><?= t('Status') ?></th>
                                <th class="text-end"><?= t('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($agents as $a): ?>
                            <tr>
                                <td><code><?= safe_output($a['agent_code']) ?></code></td>
                                <td class="fw-semibold"><?= safe_output($a['agent_name']) ?></td>
                                <td>
                                    <?php if ($a['network_name']): ?>
                                    <span class="d-inline-block me-1" style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($a['network_color'] ?: '#999') ?>"></span>
                                    <?= safe_output($a['network_name']) ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?= safe_output($a['phone']) ?></td>
                                <td><?= safe_output($a['location']) ?></td>
                                <td class="text-muted small"><?= $a['parent_name'] ? safe_output($a['parent_name']) : '—' ?></td>
                                <td class="text-center"><span class="badge bg-secondary"><?= (int)$a['till_count'] ?></span></td>
                                <td>
                                    <span class="badge <?= $a['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= ucfirst(safe_output($a['status'])) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= getUrl('mm_agent_view') ?>?id=<?= $a['agent_id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= t('View') ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($can_edit): ?>
                                    <button class="btn btn-sm btn-outline-primary" onclick='editAgent(<?= json_encode($a) ?>)' title="<?= t('Edit') ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteAgent(<?= $a['agent_id'] ?>, '<?= addslashes($a['agent_name']) ?>')" title="<?= t('Delete') ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($agents)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4"><?= t('No agents found. Add your first agent outlet.') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile card view -->
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
                            <label class="form-label"><?= t('Network') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="network_id" required>
                                <option value=""></option>
                                <?php foreach ($networks as $n): ?>
                                <option value="<?= $n['network_id'] ?>"><?= safe_output($n['network_code'].' — '.$n['network_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Phone') ?></label>
                            <input type="text" class="form-control" name="phone" placeholder="+255...">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Location / Address') ?></label>
                            <input type="text" class="form-control" name="location">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Super-Agent (if sub-agent)') ?></label>
                            <select class="form-select select2-static" name="parent_agent_id">
                                <option value=""></option>
                                <?php foreach ($parentAgents as $p): ?>
                                <option value="<?= $p['agent_id'] ?>"><?= safe_output($p['agent_code'].' — '.$p['agent_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('KYC Reference') ?></label>
                            <input type="text" class="form-control" name="kyc_reference">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Status') ?></label>
                            <select class="form-select" name="status">
                                <option value="active"><?= t('Active') ?></option>
                                <option value="inactive"><?= t('Inactive') ?></option>
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
                            <label class="form-label"><?= t('Network') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="network_id" id="edit_network" required>
                                <option value=""></option>
                                <?php foreach ($networks as $n): ?>
                                <option value="<?= $n['network_id'] ?>"><?= safe_output($n['network_code'].' — '.$n['network_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Phone') ?></label>
                            <input type="text" class="form-control" name="phone" id="edit_phone">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Location / Address') ?></label>
                            <input type="text" class="form-control" name="location" id="edit_location">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Super-Agent') ?></label>
                            <select class="form-select select2-static" name="parent_agent_id" id="edit_parent">
                                <option value=""></option>
                                <?php foreach ($parentAgents as $p): ?>
                                <option value="<?= $p['agent_id'] ?>"><?= safe_output($p['agent_code'].' — '.$p['agent_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('KYC Reference') ?></label>
                            <input type="text" class="form-control" name="kyc_reference" id="edit_kyc">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Status') ?></label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="active"><?= t('Active') ?></option>
                                <option value="inactive"><?= t('Inactive') ?></option>
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
        const dt = $('#agentsTable').DataTable({
            responsive: false, scrollX: true, pageLength: 25,
            order: [[1, 'asc']], dom: 'rtipB',
            buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
            drawCallback: function () { renderCards(this.api().rows({ page: 'current' }).data().toArray()); }
        });
    }
    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    $('#addModal, #editModal').on('shown.bs.modal', function () {
        const modal = $(this);
        modal.find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: modal, placeholder: '<?= t('Select…') ?>', allowClear: true, width: '100%' });
            }
        });
    });

    $('#addForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_agent.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) { Swal.fire({ icon:'success', title:'<?= t('Saved!') ?>', text:res.message, timer:1800, showConfirmButton:false }).then(() => location.reload()); }
                else { Swal.fire({ icon:'error', title:'<?= t('Error') ?>', text:res.message }); }
            },
            error: function () { Swal.fire({ icon:'error', title:'<?= t('Error') ?>', text:'<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('#editForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_agent.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) { Swal.fire({ icon:'success', title:'<?= t('Updated!') ?>', text:res.message, timer:1800, showConfirmButton:false }).then(() => location.reload()); }
                else { Swal.fire({ icon:'error', title:'<?= t('Error') ?>', text:res.message }); }
            },
            error: function () { Swal.fire({ icon:'error', title:'<?= t('Error') ?>', text:'<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('.modal').on('hidden.bs.modal', function () { $(this).find('form')[0]?.reset(); $(this).find('[id$="-message"]').html(''); });
});

function editAgent(a) {
    $('#edit_id').val(a.agent_id);
    $('#edit_name').val(a.agent_name);
    $('#edit_phone').val(a.phone);
    $('#edit_location').val(a.location);
    $('#edit_kyc').val(a.kyc_reference);
    $('#edit_status').val(a.status);
    $('#editModal').one('shown.bs.modal', function () {
        $('#edit_network').val(a.network_id).trigger('change');
        $('#edit_parent').val(a.parent_agent_id || '').trigger('change');
    });
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function deleteAgent(id, name) {
    Swal.fire({ title:'<?= t('Delete Agent?') ?>', text: name, icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'<?= t('Yes, Delete') ?>' })
    .then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/mobile_money/save_agent.php') ?>', { _csrf:'<?= csrf_token() ?>', _method:'DELETE', agent_id:id }, function (res) {
            if (res.success) { Swal.fire({ icon:'success', title:'<?= t('Deleted!') ?>', timer:1500, showConfirmButton:false }).then(() => location.reload()); }
            else { Swal.fire({ icon:'error', title:'<?= t('Error') ?>', text:res.message }); }
        }, 'json');
    });
}

function renderCards(rows) {
    if (!rows.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No agents found') ?></div>'); return; }
    let html = '';
    rows.forEach(row => {
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="fw-bold">${safeOutput(row[1])}</div>
                <div class="small text-muted">${safeOutput(row[2])} · ${safeOutput(row[3])}</div>
                <div class="small text-muted">${safeOutput(row[4])}</div>
            </div></div></div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
