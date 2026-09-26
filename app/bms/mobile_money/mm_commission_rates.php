<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'MM Commission Rates';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_commission_rates');
includeHeader();

$can_create = canCreate('mm_commission_rates');
$can_edit   = canEdit('mm_commission_rates');
$can_delete = canDelete('mm_commission_rates');

$networks = $pdo->query("SELECT network_id, network_code, network_name, color_hex FROM mm_networks WHERE status='active' ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

// Filter by network
$filterNet = intval($_GET['network_id'] ?? 0);
$filterType = $_GET['txn_type'] ?? '';

$whereClauses = ["r.status != 'superseded'"];
$params = [];
if ($filterNet) { $whereClauses[] = 'r.network_id = ?'; $params[] = $filterNet; }
if ($filterType) { $whereClauses[] = 'r.txn_type = ?'; $params[] = $filterType; }

$whereStr = $whereClauses ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$rates = $pdo->prepare("
    SELECT r.*, n.network_name, n.network_code, n.color_hex
    FROM mm_commission_rates r
    JOIN mm_networks n ON n.network_id = r.network_id
    $whereStr
    ORDER BY n.sort_order, r.txn_type, r.amount_from
");
$rates->execute($params);
$rates = $rates->fetchAll(PDO::FETCH_ASSOC);

$txnTypes = ['cash_in', 'cash_out', 'send', 'bill_pay', 'airtime', 'bank_to_wallet', 'wallet_to_bank', 'international'];
$txnLabels = [
    'cash_in' => 'Cash In', 'cash_out' => 'Cash Out', 'send' => 'Send Money',
    'bill_pay' => 'Bill Payment', 'airtime' => 'Airtime Top-Up',
    'bank_to_wallet' => 'Bank → Wallet', 'wallet_to_bank' => 'Wallet → Bank', 'international' => 'International'
];

logActivity($pdo, $_SESSION['user_id'], 'View MM Commission Rates', 'Viewed commission rates');
?>

<div class="container-fluid mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-percent text-primary me-2"></i><?= t('Commission Rate Schedule') ?></h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle me-1"></i> <?= t('Add Rate Band') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <form method="GET" action="" class="row g-2 mb-3">
        <div class="col-md-3">
            <select name="network_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value=""><?= t('All Networks') ?></option>
                <?php foreach ($networks as $n): ?>
                <option value="<?= $n['network_id'] ?>" <?= $filterNet == $n['network_id'] ? 'selected' : '' ?>>
                    <?= safe_output($n['network_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="txn_type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value=""><?= t('All Types') ?></option>
                <?php foreach ($txnLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= t($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($filterNet || $filterType): ?>
        <div class="col-auto">
            <a href="<?= getUrl('mm_commission_rates') ?>" class="btn btn-sm btn-outline-secondary"><?= t('Clear') ?></a>
        </div>
        <?php endif; ?>
    </form>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= count($rates) ?></div>
                <div class="small text-muted"><?= t('Rate Bands') ?></div>
            </div>
        </div>
    </div>

    <!-- Rates table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="ratesTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-dark">
                        <tr>
                            <th><?= t('Network') ?></th>
                            <th><?= t('Transaction Type') ?></th>
                            <th class="text-end"><?= t('Amount From (TZS)') ?></th>
                            <th class="text-end"><?= t('Amount To (TZS)') ?></th>
                            <th><?= t('Rate Type') ?></th>
                            <th class="text-end"><?= t('Rate Value') ?></th>
                            <th class="text-end"><?= t('Min Commission') ?></th>
                            <th class="text-end"><?= t('Max Commission') ?></th>
                            <th><?= t('From Date') ?></th>
                            <th><?= t('To Date') ?></th>
                            <?php if ($can_edit || $can_delete): ?><th class="text-end"><?= t('Actions') ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rates as $r): ?>
                        <tr>
                            <td>
                                <span class="d-inline-block me-1" style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($r['color_hex'] ?: '#999') ?>"></span>
                                <?= safe_output($r['network_name']) ?>
                            </td>
                            <td><?= t($txnLabels[$r['txn_type']] ?? $r['txn_type']) ?></td>
                            <td class="text-end"><?= number_format((float)$r['amount_from']) ?></td>
                            <td class="text-end"><?= $r['amount_to'] ? number_format((float)$r['amount_to']) : '<span class="text-muted">—</span>' ?></td>
                            <td><span class="badge <?= $r['rate_type'] === 'flat' ? 'bg-info' : 'bg-warning text-dark' ?>"><?= ucfirst($r['rate_type']) ?></span></td>
                            <td class="text-end fw-semibold">
                                <?= $r['rate_type'] === 'percent'
                                    ? number_format((float)$r['rate_value'], 2) . '%'
                                    : number_format((float)$r['rate_value']) . ' TZS' ?>
                            </td>
                            <td class="text-end text-muted"><?= $r['min_commission'] ? number_format((float)$r['min_commission']) : '—' ?></td>
                            <td class="text-end text-muted"><?= $r['max_commission'] ? number_format((float)$r['max_commission']) : '—' ?></td>
                            <td class="small"><?= safe_output($r['effective_from']) ?></td>
                            <td class="small"><?= $r['effective_to'] ?: '<span class="text-muted">—</span>' ?></td>
                            <?php if ($can_edit || $can_delete): ?>
                            <td class="text-end">
                                <?php if ($can_edit): ?>
                                <button class="btn btn-sm btn-outline-primary" onclick='editRate(<?= json_encode($r) ?>)'><i class="bi bi-pencil"></i></button>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteRate(<?= $r['rate_id'] ?>)"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($rates)): ?>
                        <tr><td colspan="11" class="text-center text-muted py-4"><?= t('No rate bands found.') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> <?= t('Add Rate Band') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="row g-3">
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
                            <label class="form-label"><?= t('Transaction Type') ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="txn_type" required>
                                <?php foreach ($txnLabels as $k => $v): ?>
                                <option value="<?= $k ?>"><?= t($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Amount From (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount_from" required min="0" step="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Amount To (TZS)') ?></label>
                            <input type="number" class="form-control" name="amount_to" min="0" step="1" placeholder="<?= t('Leave blank = no limit') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Rate Type') ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="rate_type" id="add_rate_type" onchange="toggleRateLabel(this, 'add_rate_hint')">
                                <option value="flat"><?= t('Flat (TZS)') ?></option>
                                <option value="percent"><?= t('Percent (%)') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Rate Value') ?> <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="rate_value" required min="0" step="0.0001">
                                <span class="input-group-text" id="add_rate_hint">TZS</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Min Commission (TZS)') ?></label>
                            <input type="number" class="form-control" name="min_commission" min="0" step="1" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Max Commission (TZS)') ?></label>
                            <input type="number" class="form-control" name="max_commission" min="0" step="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Effective From') ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="effective_from" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Effective To') ?></label>
                            <input type="date" class="form-control" name="effective_to">
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
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> <?= t('Edit Rate Band') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="rate_id" id="edit_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="row g-3">
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
                            <label class="form-label"><?= t('Transaction Type') ?></label>
                            <select class="form-select" name="txn_type" id="edit_txn_type">
                                <?php foreach ($txnLabels as $k => $v): ?>
                                <option value="<?= $k ?>"><?= t($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Amount From') ?></label>
                            <input type="number" class="form-control" name="amount_from" id="edit_from" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Amount To') ?></label>
                            <input type="number" class="form-control" name="amount_to" id="edit_to" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Rate Type') ?></label>
                            <select class="form-select" name="rate_type" id="edit_rate_type" onchange="toggleRateLabel(this, 'edit_rate_hint')">
                                <option value="flat"><?= t('Flat (TZS)') ?></option>
                                <option value="percent"><?= t('Percent (%)') ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Rate Value') ?></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="rate_value" id="edit_value" min="0" step="0.0001">
                                <span class="input-group-text" id="edit_rate_hint">TZS</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Min Commission') ?></label>
                            <input type="number" class="form-control" name="min_commission" id="edit_min" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Max Commission') ?></label>
                            <input type="number" class="form-control" name="max_commission" id="edit_max" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Effective From') ?></label>
                            <input type="date" class="form-control" name="effective_from" id="edit_eff_from">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Effective To') ?></label>
                            <input type="date" class="form-control" name="effective_to" id="edit_eff_to">
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
    if (!$.fn.DataTable.isDataTable('#ratesTable')) {
        $('#ratesTable').DataTable({ responsive:false, scrollX:true, pageLength:25, order:[[0,'asc'],[1,'asc'],[2,'asc']], dom:'rtipB' });
    }

    ['#addModal','#editModal'].forEach(m => {
        $(m).on('shown.bs.modal', function () {
            const modal = $(this);
            modal.find('.select2-static').each(function () {
                if (!$(this).hasClass('select2-hidden-accessible'))
                    $(this).select2({ theme:'bootstrap-5', dropdownParent:modal, placeholder:'<?= t('Select…') ?>', allowClear:true, width:'100%' });
            });
        });
    });

    $('#addForm').on('submit', function (e) {
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({ url:'<?= buildUrl('api/mobile_money/save_commission_rate.php') ?>', type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
            success: r => { if(r.success){Swal.fire({icon:'success',title:'<?= t('Saved!') ?>',timer:1500,showConfirmButton:false}).then(()=>location.reload());} else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});} },
            error: ()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });

    $('#editForm').on('submit', function (e) {
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({ url:'<?= buildUrl('api/mobile_money/save_commission_rate.php') ?>', type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
            success: r => { if(r.success){Swal.fire({icon:'success',title:'<?= t('Updated!') ?>',timer:1500,showConfirmButton:false}).then(()=>location.reload());} else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});} },
            error: ()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });

    $('.modal').on('hidden.bs.modal', function(){$(this).find('form')[0]?.reset();});
});

function toggleRateLabel(sel, hintId) {
    document.getElementById(hintId).textContent = sel.value === 'percent' ? '%' : 'TZS';
}

function editRate(r) {
    $('#edit_id').val(r.rate_id);
    $('#edit_txn_type').val(r.txn_type);
    $('#edit_from').val(r.amount_from);
    $('#edit_to').val(r.amount_to);
    $('#edit_rate_type').val(r.rate_type);
    toggleRateLabel({value:r.rate_type}, 'edit_rate_hint');
    $('#edit_value').val(r.rate_value);
    $('#edit_min').val(r.min_commission);
    $('#edit_max').val(r.max_commission);
    $('#edit_eff_from').val(r.effective_from);
    $('#edit_eff_to').val(r.effective_to);
    $('#editModal').one('shown.bs.modal', function(){ $('#edit_network').val(r.network_id).trigger('change'); });
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function deleteRate(id) {
    Swal.fire({ title:'<?= t('Delete Rate Band?') ?>', icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'<?= t('Yes, Delete') ?>' })
    .then(r => {
        if(!r.isConfirmed) return;
        $.post('<?= buildUrl('api/mobile_money/save_commission_rate.php') ?>', {_csrf:'<?= csrf_token() ?>',_method:'DELETE',rate_id:id}, res=>{
            if(res.success){Swal.fire({icon:'success',title:'<?= t('Deleted!') ?>',timer:1400,showConfirmButton:false}).then(()=>location.reload());}
            else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:res.message});}
        },'json');
    });
}
</script>

<?php includeFooter(); ?>
