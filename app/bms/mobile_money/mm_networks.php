<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'MM Networks';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_networks');
includeHeader();

$can_create = canCreate('mm_networks');
$can_edit   = canEdit('mm_networks');

$networks = $pdo->query("
    SELECT n.*,
           a_f.account_code AS float_code, a_f.account_name AS float_name,
           a_c.account_code AS comm_code,  a_c.account_name AS comm_name,
           (SELECT COUNT(DISTINCT t.agent_id) FROM mm_tills t WHERE t.network_id = n.network_id AND t.status = 'active') AS agent_count
    FROM mm_networks n
    LEFT JOIN accounts a_f ON a_f.account_id = n.float_account_id
    LEFT JOIN accounts a_c ON a_c.account_id = n.commission_account_id
    ORDER BY n.sort_order, n.network_name
")->fetchAll(PDO::FETCH_ASSOC);

// Asset accounts for dropdown
$assetAccounts = $pdo->query("
    SELECT account_id, account_code, account_name
    FROM accounts WHERE account_type = 'asset' AND status = 'active'
    ORDER BY account_code
")->fetchAll(PDO::FETCH_ASSOC);

// Income accounts for dropdown
$incomeAccounts = $pdo->query("
    SELECT account_id, account_code, account_name
    FROM accounts WHERE account_type = 'income' AND status = 'active'
    ORDER BY account_code
")->fetchAll(PDO::FETCH_ASSOC);

logActivity($pdo, $_SESSION['user_id'], 'View MM Networks', 'Viewed Mobile Money networks list');
?>

<div class="container-fluid mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-broadcast text-primary me-2"></i><?= t('Mobile Money Networks') ?></h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle me-1"></i> <?= t('Add Network') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-primary"><?= count($networks) ?></div>
                <div class="small text-muted"><?= t('Total Networks') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-success"><?= count(array_filter($networks, fn($n) => $n['status'] === 'active')) ?></div>
                <div class="small text-muted"><?= t('Active') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-info"><?= array_sum(array_column($networks, 'agent_count')) ?></div>
                <div class="small text-muted"><?= t('Total Agents') ?></div>
            </div>
        </div>
    </div>

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
    <!-- Desktop table -->
    <div id="tableView">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="networksTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="mm-thead">
                        <tr>
                            <th class="text-center" style="width:48px"><?= t('S/No') ?></th>
                            <th class="text-center"><?= t('Network') ?></th>
                            <th class="text-center"><?= t('Code') ?></th>
                            <th class="text-center"><?= t('Short Code') ?></th>
                            <th class="text-center"><?= t('E-Float Account') ?></th>
                            <th class="text-center"><?= t('Commission Account') ?></th>
                            <th class="text-center"><?= t('Agents') ?></th>
                            <th class="text-center"><?= t('Status') ?></th>
                            <?php if ($can_edit): ?><th class="text-center"><?= t('Actions') ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($networks as $n): ?>
                        <tr data-id="<?= (int)$n['network_id'] ?>" data-name="<?= htmlspecialchars($n['network_name']) ?>" data-code="<?= htmlspecialchars($n['network_code']) ?>" data-status="<?= htmlspecialchars($n['status']) ?>" data-color="<?= htmlspecialchars($n['color_hex'] ?: '#6c757d') ?>" data-agents="<?= (int)$n['agent_count'] ?>" data-can-edit="<?= $can_edit ? '1' : '0' ?>">
                            <td class="text-center text-muted small"><?= $sno++ ?></td>
                            <td>
                                <span class="d-inline-block me-2" style="width:12px;height:12px;border-radius:50%;background:<?= htmlspecialchars($n['color_hex'] ?: '#999') ?>"></span>
                                <strong><?= safe_output($n['network_name']) ?></strong>
                                <?php if ($n['provider']): ?><small class="text-muted ms-1">(<?= safe_output($n['provider']) ?>)</small><?php endif; ?>
                            </td>
                            <td><code><?= safe_output($n['network_code']) ?></code></td>
                            <td><?= safe_output($n['short_code']) ?></td>
                            <td class="small text-muted"><?= $n['float_code'] ? safe_output($n['float_code'].' — '.$n['float_name']) : '<span class="text-warning">Not set</span>' ?></td>
                            <td class="small text-muted"><?= $n['comm_code'] ? safe_output($n['comm_code'].' — '.$n['comm_name']) : '<span class="text-warning">Not set</span>' ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= (int)$n['agent_count'] ?></span></td>
                            <td>
                                <span class="badge <?= $n['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= ucfirst(safe_output($n['status'])) ?>
                                </span>
                            </td>
                            <?php if ($can_edit): ?>
                            <td class="text-center">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-gear-fill"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:120px;font-size:.85rem">
                                        <li><a class="dropdown-item mm-net-edit" href="#" data-net-id="<?= (int)$n['network_id'] ?>"><i class="bi bi-pencil me-2 text-warning"></i><?= t('Edit') ?></a></li>
                                    </ul>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($networks)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4"><?= t('No networks configured.') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    </div><!-- end tableView -->
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Add Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> <?= t('Add Network') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="add-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label"><?= t('Network Name') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="network_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= t('Code') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control text-uppercase" name="network_code" maxlength="20" required placeholder="e.g. MPESA">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Provider / MNO') ?></label>
                            <input type="text" class="form-control" name="provider" placeholder="e.g. Vodacom Tanzania">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?= t('Short Code') ?></label>
                            <input type="text" class="form-control" name="short_code" placeholder="e.g. *150*00#">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?= t('Colour') ?></label>
                            <input type="color" class="form-control form-control-color" name="color_hex" value="#198754">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('E-Float Account') ?></label>
                            <select class="form-select select2-static" name="float_account_id">
                                <option value=""></option>
                                <?php foreach ($assetAccounts as $a): ?>
                                <option value="<?= $a['account_id'] ?>"><?= safe_output($a['account_code'].' — '.$a['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Commission Income Account') ?></label>
                            <select class="form-select select2-static" name="commission_account_id">
                                <option value=""></option>
                                <?php foreach ($incomeAccounts as $a): ?>
                                <option value="<?= $a['account_id'] ?>"><?= safe_output($a['account_code'].' — '.$a['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= t('Sort Order') ?></label>
                            <input type="number" class="form-control" name="sort_order" value="10" min="0">
                        </div>
                        <div class="col-md-4">
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
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> <?= t('Edit Network') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="network_id" id="edit_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="edit-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label"><?= t('Network Name') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="network_name" id="edit_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= t('Code') ?></label>
                            <input type="text" class="form-control" name="network_code" id="edit_code" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Provider / MNO') ?></label>
                            <input type="text" class="form-control" name="provider" id="edit_provider">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?= t('Short Code') ?></label>
                            <input type="text" class="form-control" name="short_code" id="edit_short_code">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?= t('Colour') ?></label>
                            <input type="color" class="form-control form-control-color" name="color_hex" id="edit_color">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('E-Float Account') ?></label>
                            <select class="form-select select2-static" name="float_account_id" id="edit_float_account">
                                <option value=""></option>
                                <?php foreach ($assetAccounts as $a): ?>
                                <option value="<?= $a['account_id'] ?>"><?= safe_output($a['account_code'].' — '.$a['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Commission Income Account') ?></label>
                            <select class="form-select select2-static" name="commission_account_id" id="edit_comm_account">
                                <option value=""></option>
                                <?php foreach ($incomeAccounts as $a): ?>
                                <option value="<?= $a['account_id'] ?>"><?= safe_output($a['account_code'].' — '.$a['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?= t('Sort Order') ?></label>
                            <input type="number" class="form-control" name="sort_order" id="edit_sort">
                        </div>
                        <div class="col-md-4">
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
    if (!$.fn.DataTable.isDataTable('#networksTable')) {
        $('#networksTable').DataTable({ responsive:false, scrollX:true, pageLength:25, order:[[1,'asc']], columnDefs:[{orderable:false,targets:0}], dom:'rtipB',
            language: { emptyTable: '<?= addslashes(t('No networks configured.')) ?>' },
            drawCallback: function(){ renderCards(this.api().rows({page:'current'}).nodes()); }
        });
    }
    function applyView(){if(window.innerWidth<768){$('#tableView').addClass('d-none');$('#cardView').removeClass('d-none');}else{$('#tableView').removeClass('d-none');$('#cardView').addClass('d-none');}}
    applyView(); $(window).on('resize',applyView);

    // Init Select2 in modals
    $('#addModal, #editModal').on('shown.bs.modal', function () {
        const modal = $(this);
        modal.find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: modal, placeholder: '<?= t('Select account…') ?>', allowClear: true, width: '100%' });
            }
        });
    });

    $('#addForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> <?= t('Saving…') ?>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_network.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Saved!') ?>', text: res.message, timer: 1800, showConfirmButton: false }).then(() => location.reload());
                } else { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message }); }
            },
            error: function (xhr) { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: xhr.responseJSON?.message||'<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('#editForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> <?= t('Saving…') ?>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_network.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Updated!') ?>', text: res.message, timer: 1800, showConfirmButton: false }).then(() => location.reload());
                } else { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message }); }
            },
            error: function (xhr) { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: xhr.responseJSON?.message||'<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('.modal').on('hidden.bs.modal', function () {
        $(this).find('form')[0]?.reset();
        $(this).find('[id$="-message"]').html('');
    });
});

function renderCards(nodes) {
    if (!nodes.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No networks configured') ?></div>'); return; }
    window.__mmNetData = window.__mmNetData || {};
    let html = '';
    $(nodes).each(function (idx) {
        const $tr = $(this);
        const id = $tr.data('id'), sno = idx + 1;
        const name = $tr.data('name'), code = $tr.data('code');
        const status = $tr.data('status'), color = $tr.data('color');
        const agents = $tr.data('agents'), canEdit = $tr.data('can-edit') == 1;
        window.__mmNetData[id] = JSON.parse($tr.attr('data-agent') || 'null') || {};
        const sBadge = status === 'active' ? 'bg-success' : 'bg-secondary';
        html += `<div class="col-12"><div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
          <div class="card-body p-3 pb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="mm-sno">${sno}</span>
              <span class="badge ${sBadge}" style="font-size:.73rem">${safeOutput(status.charAt(0).toUpperCase()+status.slice(1))}</span>
            </div>
            <div class="fw-semibold mb-2" style="font-size:.95rem">
              <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${safeOutput(color)};margin-right:6px"></span>${safeOutput(name)}
            </div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Code') ?></span><span class="kv-val"><code>${safeOutput(code)}</code></span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Agents') ?></span><span class="kv-val">${safeOutput(String(agents))}</span></div>
          </div>
          ${canEdit ? `<div class="mm-card-foot"><button class="btn btn-sm btn-outline-primary mm-net-edit" data-net-id="${id}"><i class="bi bi-pencil me-1"></i><?= t('Edit') ?></button></div>` : ''}
        </div></div>`;
    });
    $('#cardView').html(html);
}
$(document).on('click', '.mm-net-edit', function () {
    const id = $(this).data('net-id');
    const $tr = $('#networksTable').DataTable().rows().nodes().filter(n => $(n).data('id') == id);
    if ($tr.length) { const n = {}; Object.keys($($tr[0]).data()).forEach(k => n[k] = $($tr[0]).data(k)); editNetwork(Object.assign({ network_id: id, network_name: $($tr[0]).data('name'), network_code: $($tr[0]).data('code'), status: $($tr[0]).data('status'), color_hex: $($tr[0]).data('color') }, n)); }
});

function editNetwork(n) {
    $('#edit_id').val(n.network_id);
    $('#edit_name').val(n.network_name);
    $('#edit_code').val(n.network_code);
    $('#edit_provider').val(n.provider);
    $('#edit_short_code').val(n.short_code);
    $('#edit_color').val(n.color_hex || '#198754');
    $('#edit_sort').val(n.sort_order);
    $('#edit_status').val(n.status);
    const fm = new bootstrap.Modal(document.getElementById('editModal'));
    // Set Select2 values after modal opens
    $('#editModal').one('shown.bs.modal', function () {
        $('#edit_float_account').val(n.float_account_id).trigger('change');
        $('#edit_comm_account').val(n.commission_account_id).trigger('change');
    });
    fm.show();
}
</script>

<?php includeFooter(); ?>
