<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'MM Shifts';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_shifts');
includeHeader();

$can_open  = canCreate('mm_shifts');
$can_close = canEdit('mm_shifts');

// Get all active tills with agent name for dropdowns
$tillsForOpen = $pdo->query("
    SELECT t.till_id, t.till_number, a.agent_name, n.network_name, n.color_hex
    FROM mm_tills t
    JOIN mm_agents a ON a.agent_id = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    WHERE t.status = 'active'
    ORDER BY a.agent_name, t.till_number
")->fetchAll(PDO::FETCH_ASSOC);

// Filters
$filterFrom   = $_GET['date_from'] ?? date('Y-m-01');
$filterTo     = $_GET['date_to']   ?? date('Y-m-d');
$filterStatus = $_GET['status']    ?? '';

$where  = ["s.opened_at >= :df", "s.opened_at <= :dt"];
$params = [':df' => $filterFrom . ' 00:00:00', ':dt' => $filterTo . ' 23:59:59'];
if ($filterStatus) { $where[] = 's.status = :status'; $params[':status'] = $filterStatus; }

$shifts = $pdo->prepare("
    SELECT s.*,
           t.till_number, a.agent_name, a.agent_code, n.network_name, n.color_hex,
           u.full_name AS teller_name, uc.full_name AS closed_by_name,
           (SELECT COUNT(*) FROM mm_transactions mt WHERE mt.shift_id = s.shift_id AND mt.status = 'posted') AS txn_count,
           (SELECT SUM(mt.principal_amount) FROM mm_transactions mt WHERE mt.shift_id = s.shift_id AND mt.status = 'posted') AS txn_volume,
           (SELECT SUM(mt.commission_earned) FROM mm_transactions mt WHERE mt.shift_id = s.shift_id AND mt.status = 'posted') AS txn_commission
    FROM mm_shifts s
    JOIN mm_tills t   ON t.till_id = s.till_id
    JOIN mm_agents a  ON a.agent_id = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    LEFT JOIN users u  ON u.user_id = s.teller_user_id
    LEFT JOIN users uc ON uc.user_id = s.closed_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.opened_at DESC
    LIMIT 200
");
$shifts->execute($params);
$shifts = $shifts->fetchAll(PDO::FETCH_ASSOC);

$openCount   = count(array_filter($shifts, fn($s) => $s['status'] === 'open'));
$closedCount = count(array_filter($shifts, fn($s) => $s['status'] === 'closed'));

logActivity($pdo, $_SESSION['user_id'], 'View MM Shifts', 'Viewed shifts list');
?>

<div class="container-fluid mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i><?= t('Teller Shifts') ?></h4>
        <?php if ($can_open): ?>
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#openShiftModal">
            <i class="bi bi-play-circle me-1"></i><?= t('Open Shift') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" action="" class="row g-2 mb-3 align-items-end">
        <div class="col-md-2">
            <label class="form-label small mb-1"><?= t('From') ?></label>
            <input type="date" class="form-control form-control-sm" name="date_from" value="<?= htmlspecialchars($filterFrom) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1"><?= t('To') ?></label>
            <input type="date" class="form-control form-control-sm" name="date_to" value="<?= htmlspecialchars($filterTo) ?>">
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm">
                <option value=""><?= t('All Statuses') ?></option>
                <option value="open" <?= $filterStatus==='open'?'selected':'' ?>><?= t('Open') ?></option>
                <option value="closed" <?= $filterStatus==='closed'?'selected':'' ?>><?= t('Closed') ?></option>
                <option value="forced_close" <?= $filterStatus==='forced_close'?'selected':'' ?>><?= t('Force-closed') ?></option>
            </select>
        </div>
        <div class="col-md-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="<?= getUrl('mm_shifts') ?>" class="btn btn-sm btn-outline-secondary"><?= t('Clear') ?></a>
        </div>
    </form>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success"><?= $openCount ?></div>
                <div class="small text-muted"><?= t('Open Shifts') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= $closedCount ?></div>
                <div class="small text-muted"><?= t('Closed Shifts') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold text-info"><?= number_format(array_sum(array_column($shifts, 'txn_volume'))) ?></div>
                <div class="small text-muted"><?= t('Total Volume (TZS)') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold text-warning"><?= number_format(array_sum(array_column($shifts, 'txn_commission'))) ?></div>
                <div class="small text-muted"><?= t('Commission (TZS)') ?></div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="shiftsTable" class="table table-hover align-middle mb-0 w-100" style="font-size:.88rem">
                        <thead class="table-dark">
                            <tr>
                                <th><?= t('Code') ?></th>
                                <th><?= t('Outlet / Till') ?></th>
                                <th><?= t('Teller') ?></th>
                                <th><?= t('Opened') ?></th>
                                <th><?= t('Closed') ?></th>
                                <th class="text-end"><?= t('Txns') ?></th>
                                <th class="text-end"><?= t('Volume (TZS)') ?></th>
                                <th class="text-end"><?= t('Cash Var.') ?></th>
                                <th class="text-end"><?= t('Float Var.') ?></th>
                                <th><?= t('Status') ?></th>
                                <th class="text-end"><?= t('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shifts as $sh): ?>
                            <tr>
                                <td><code class="small"><?= safe_output($sh['shift_code']) ?></code></td>
                                <td class="small">
                                    <span class="d-inline-block me-1" style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($sh['color_hex'] ?: '#999') ?>"></span>
                                    <?= safe_output($sh['agent_name']) ?> / <?= safe_output($sh['till_number']) ?>
                                </td>
                                <td class="small"><?= safe_output($sh['teller_name'] ?: '—') ?></td>
                                <td class="small"><?= date('d M H:i', strtotime($sh['opened_at'])) ?></td>
                                <td class="small"><?= $sh['closed_at'] ? date('d M H:i', strtotime($sh['closed_at'])) : '—' ?></td>
                                <td class="text-end"><?= (int)$sh['txn_count'] ?></td>
                                <td class="text-end"><?= $sh['txn_volume'] ? number_format((float)$sh['txn_volume']) : '—' ?></td>
                                <td class="text-end <?= ($sh['cash_variance'] ?? 0) == 0 ? 'text-muted' : (($sh['cash_variance'] ?? 0) > 0 ? 'text-success' : 'text-danger') ?>">
                                    <?= $sh['cash_variance'] !== null ? number_format((float)$sh['cash_variance']) : '—' ?>
                                </td>
                                <td class="text-end <?= ($sh['float_variance'] ?? 0) == 0 ? 'text-muted' : (($sh['float_variance'] ?? 0) > 0 ? 'text-success' : 'text-danger') ?>">
                                    <?= $sh['float_variance'] !== null ? number_format((float)$sh['float_variance']) : '—' ?>
                                </td>
                                <td>
                                    <span class="badge <?= $sh['status']==='open'?'bg-success':($sh['status']==='forced_close'?'bg-danger':'bg-secondary') ?>">
                                        <?= ucfirst(str_replace('_', ' ', safe_output($sh['status']))) ?>
                                    </span>
                                </td>
                                <td class="text-end d-flex gap-1 justify-content-end">
                                    <a href="<?= getUrl('mm_shift_report') ?>?id=<?= $sh['shift_id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= t('Report') ?>"><i class="bi bi-printer"></i></a>
                                    <?php if ($sh['status'] === 'open' && $can_close): ?>
                                    <button class="btn btn-sm btn-outline-danger" onclick='closeShift(<?= json_encode(['id'=>$sh['shift_id'],'code'=>$sh['shift_code'],'till_number'=>$sh['till_number'],'agent'=>$sh['agent_name']]) ?>)' title="<?= t('Close') ?>">
                                        <i class="bi bi-stop-circle"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($shifts)): ?>
                            <tr><td colspan="11" class="text-center text-muted py-5"><?= t('No shifts found.') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Open Shift Modal -->
<?php if ($can_open): ?>
<div class="modal fade" id="openShiftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-play-circle me-1"></i><?= t('Open Shift') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="openShiftForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= t('Till') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="till_id" required>
                                <option value=""></option>
                                <?php foreach ($tillsForOpen as $t): ?>
                                <option value="<?= $t['till_id'] ?>"><?= safe_output($t['agent_name'].' / '.$t['till_number'].' ('.$t['network_name'].')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Opening Cash (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="opening_cash" min="0" step="100" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Opening Float (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="opening_float" min="0" step="100" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-play-circle me-1"></i><?= t('Open Shift') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Close Shift Modal -->
<?php if ($can_close): ?>
<div class="modal fade" id="closeShiftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-stop-circle me-1"></i><?= t('Close Shift') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="closeShiftForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="shift_id" id="close_shift_id">
                    <div id="close_shift_info" class="alert alert-light py-2 mb-3 small"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Counted Cash (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="closing_cash" id="close_cash" min="0" step="100" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Counted Float (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="closing_float" id="close_float" min="0" step="100" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Close Notes') ?></label>
                            <input type="text" class="form-control" name="close_notes" id="close_notes_field" placeholder="<?= t('Any issues or comments…') ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-stop-circle me-1"></i><?= t('Close Shift') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#shiftsTable')) {
        $('#shiftsTable').DataTable({ responsive:false, scrollX:true, pageLength:25, order:[[3,'desc']], dom:'rtipB',
            buttons:[{extend:'excelHtml5',className:'d-none',exportOptions:{columns:':not(:last-child)'}}],
            drawCallback: function() { renderCards(this.api().rows({page:'current'}).data().toArray()); }
        });
    }
    function applyView(){if(window.innerWidth<768){$('#tableView').addClass('d-none');$('#cardView').removeClass('d-none');}else{$('#tableView').removeClass('d-none');$('#cardView').addClass('d-none');}}
    applyView(); $(window).on('resize',applyView);

    $('#openShiftModal').on('shown.bs.modal', function(){
        const modal=$(this);
        modal.find('.select2-static').each(function(){if(!$(this).hasClass('select2-hidden-accessible'))$(this).select2({theme:'bootstrap-5',dropdownParent:modal,placeholder:'<?= t('Select till…') ?>',allowClear:true,width:'100%'});});
    });

    $('#openShiftForm').on('submit', function(e){
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/open_shift.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success:r=>{if(r.success){Swal.fire({icon:'success',title:'<?= t('Shift Opened!') ?>',text:r.message,timer:1800,showConfirmButton:false}).then(()=>location.reload());}else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});}},
            error:()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });

    $('#closeShiftForm').on('submit', function(e){
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/close_shift.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success:r=>{if(r.success){Swal.fire({icon:'success',title:'<?= t('Shift Closed!') ?>',text:r.message,timer:2000,showConfirmButton:false}).then(()=>location.reload());}else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});}},
            error:()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete:()=>btn.prop('disabled',false).html(orig)
        });
    });

    $('.modal').on('hidden.bs.modal', function(){$(this).find('form')[0]?.reset();});
});

function closeShift(s){
    $('#close_shift_id').val(s.id);
    $('#close_shift_info').html('<strong><?= t('Shift:') ?> '+safeOutput(s.code)+'</strong> · '+safeOutput(s.agent)+' / '+safeOutput(s.till_number));
    new bootstrap.Modal(document.getElementById('closeShiftModal')).show();
}

function renderCards(rows){
    if(!rows.length){$('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No shifts') ?></div>');return;}
    let html='';
    rows.forEach(r=>{html+=`<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3"><div class="fw-bold">${safeOutput(r[0])}</div><div class="small text-muted">${safeOutput(r[1])}</div></div></div></div>`;});
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
