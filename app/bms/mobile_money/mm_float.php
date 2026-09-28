<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'Float Management';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_float');
includeHeader();

$can_create = canCreate('mm_float');
$can_edit   = canEdit('mm_float');
$can_open_shift = canCreate('mm_shifts');

// Current user's active shift (for banner)
$myShiftStmt = $pdo->prepare("
    SELECT s.shift_id, s.shift_code, t.till_number, a.agent_name, s.opened_at
    FROM mm_shifts s
    JOIN mm_tills t ON t.till_id = s.till_id
    JOIN mm_agents a ON a.agent_id = t.agent_id
    WHERE s.teller_user_id = ? AND s.status = 'open'
    LIMIT 1
");
$myShiftStmt->execute([$_SESSION['user_id']]);
$myActiveShift = $myShiftStmt->fetch(PDO::FETCH_ASSOC);

// Filters
$filterFrom   = $_GET['date_from'] ?? date('Y-m-01');
$filterTo     = $_GET['date_to']   ?? date('Y-m-d');
$filterTill   = intval($_GET['till_id'] ?? 0);
$filterType   = $_GET['mov_type'] ?? '';

$tills = $pdo->query("
    SELECT t.till_id, t.till_number, a.agent_name, n.network_name
    FROM mm_tills t
    JOIN mm_agents a ON a.agent_id = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    WHERE t.status = 'active'
    ORDER BY a.agent_name, t.till_number
")->fetchAll(PDO::FETCH_ASSOC);

$where  = ["fm.movement_date BETWEEN :df AND :dt"];
$params = [':df' => $filterFrom, ':dt' => $filterTo];
if ($filterTill) { $where[] = 'fm.till_id = :till_id'; $params[':till_id'] = $filterTill; }
if ($filterType) { $where[] = 'fm.movement_type = :mov_type'; $params[':mov_type'] = $filterType; }

$movements = $pdo->prepare("
    SELECT fm.*,
           t.till_number, a.agent_name, n.network_name, n.color_hex,
           ac.account_code AS bank_acct_code, ac.account_name AS bank_acct_name,
           CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
    FROM mm_float_movements fm
    JOIN mm_tills t    ON t.till_id    = fm.till_id
    JOIN mm_agents a   ON a.agent_id   = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    LEFT JOIN accounts ac ON ac.account_id = fm.bank_account_id
    LEFT JOIN users u  ON u.user_id    = fm.created_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY fm.movement_date DESC, fm.created_at DESC
    LIMIT 300
");
$movements->execute($params);
$movements = $movements->fetchAll(PDO::FETCH_ASSOC);

$totalTopup  = array_sum(array_column(array_filter($movements, fn($m) => $m['movement_type'] === 'float_topup'), 'amount'));
$totalWithdr = array_sum(array_column(array_filter($movements, fn($m) => $m['movement_type'] === 'float_withdrawal'), 'amount'));

$movLabels = [
    'float_topup'       => 'Float Top-up',
    'float_withdrawal'  => 'Float Withdrawal',
    'opening_balance'   => 'Opening Balance',
    'adjustment'        => 'Adjustment',
];

logActivity($pdo, $_SESSION['user_id'], 'View MM Float', 'Viewed float movements');
?>

<div class="container-fluid mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-currency-exchange text-primary me-2"></i><?= t('Float Management') ?></h4>
        <?php if ($can_create): ?>
        <div class="d-flex gap-2">
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#topupModal">
                <i class="bi bi-arrow-down-circle me-1"></i><?= t('Float Top-up') ?>
            </button>
            <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#withdrawModal">
                <i class="bi bi-arrow-up-circle me-1"></i><?= t('Withdrawal') ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Shift banner (Change C) -->
    <?php if ($myActiveShift): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center py-2 mb-3" style="border-radius:8px">
        <span><i class="bi bi-play-circle-fill me-2"></i>
        <strong><?= t('Active Shift') ?>:</strong> <?= safe_output($myActiveShift['shift_code']) ?>
        &nbsp;·&nbsp; <?= safe_output($myActiveShift['agent_name'].' / '.$myActiveShift['till_number']) ?>
        &nbsp;·&nbsp; <?= t('Started') ?>: <?= date('H:i', strtotime($myActiveShift['opened_at'])) ?>
        </span>
        <a href="<?= getUrl('mm_shifts') ?>" class="btn btn-sm btn-outline-success ms-2"><?= t('Shifts') ?> <i class="bi bi-arrow-right ms-1"></i></a>
    </div>
    <?php else: ?>
    <div class="alert alert-light d-flex justify-content-between align-items-center py-2 mb-3" style="border-radius:8px;border:1px solid #dee2e6">
        <span class="text-muted"><i class="bi bi-clock me-2"></i><?= t('No active shift. Float movements can still be recorded by authorized staff.') ?></span>
        <?php if ($can_open_shift): ?>
        <a href="<?= getUrl('mm_shifts') ?>" class="btn btn-sm btn-outline-primary ms-2"><?= t('Shifts') ?> <i class="bi bi-arrow-right ms-1"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-success"><?= number_format($totalTopup) ?></div>
                <div class="small text-muted"><?= t('Top-ups (TZS)') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-warning"><?= number_format($totalWithdr) ?></div>
                <div class="small text-muted"><?= t('Withdrawals (TZS)') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-info"><?= count($movements) ?></div>
                <div class="small text-muted"><?= t('Movements') ?></div>
            </div>
        </div>
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
        <div class="col-md-3">
            <select name="till_id" class="form-select form-select-sm">
                <option value=""><?= t('All Tills') ?></option>
                <?php foreach ($tills as $t): ?>
                <option value="<?= $t['till_id'] ?>" <?= $filterTill == $t['till_id'] ? 'selected' : '' ?>><?= safe_output($t['agent_name'].' / '.$t['till_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="mov_type" class="form-select form-select-sm">
                <option value=""><?= t('All Types') ?></option>
                <?php foreach ($movLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= t($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="<?= getUrl('mm_float') ?>" class="btn btn-sm btn-outline-secondary"><?= t('Clear') ?></a>
        </div>
    </form>

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
    <!-- Table -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="floatTable" class="table table-hover align-middle mb-0 w-100" style="font-size:.88rem">
                        <thead class="mm-thead">
                            <tr>
                                <th class="text-center" style="width:48px"><?= t('S/No') ?></th>
                                <th class="text-center"><?= t('Code') ?></th>
                                <th class="text-center"><?= t('Date') ?></th>
                                <th class="text-center"><?= t('Outlet / Till') ?></th>
                                <th class="text-center"><?= t('Type') ?></th>
                                <th class="text-center"><?= t('Amount (TZS)') ?></th>
                                <th class="text-center"><?= t('Reference') ?></th>
                                <th class="text-center"><?= t('Status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sno = 1; foreach ($movements as $m): ?>
                            <tr data-id="<?= (int)$m['movement_id'] ?>" data-code="<?= htmlspecialchars($m['movement_code']) ?>" data-date="<?= htmlspecialchars($m['movement_date']) ?>" data-outlet="<?= htmlspecialchars($m['agent_name'].' / '.$m['till_number']) ?>" data-type="<?= htmlspecialchars($movLabels[$m['movement_type']] ?? $m['movement_type']) ?>" data-amount="<?= number_format((float)$m['amount']) ?>" data-status="<?= htmlspecialchars($m['status']) ?>">
                                <td class="text-center text-muted small"><?= $sno++ ?></td>
                                <td><code class="small"><?= safe_output($m['movement_code']) ?></code></td>
                                <td class="small"><?= safe_output($m['movement_date']) ?></td>
                                <td class="small">
                                    <span class="d-inline-block me-1" style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($m['color_hex'] ?: '#999') ?>"></span>
                                    <?= safe_output($m['agent_name']) ?> / <?= safe_output($m['till_number']) ?>
                                </td>
                                <td>
                                    <span class="badge <?= $m['movement_type']==='float_topup'?'bg-success':($m['movement_type']==='float_withdrawal'?'bg-warning text-dark':'bg-info text-dark') ?>">
                                        <?= t($movLabels[$m['movement_type']] ?? $m['movement_type']) ?>
                                    </span>
                                </td>
                                <td class="text-end fw-semibold"><?= number_format((float)$m['amount']) ?></td>
                                <td class="small text-muted"><?= safe_output($m['reference_no'] ?: '—') ?></td>
                                <td>
                                    <span class="badge <?= $m['status']==='posted'?'bg-success':($m['status']==='void'?'bg-danger':'bg-secondary') ?>">
                                        <?= ucfirst(safe_output($m['status'])) ?>
                                    </span>
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

<!-- Top-up Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="topupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-down-circle me-1"></i><?= t('Float Top-up') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="topupForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="movement_type" value="float_topup">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= t('Till') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="till_id" required>
                                <option value=""></option>
                                <?php foreach ($tills as $t): ?>
                                <option value="<?= $t['till_id'] ?>"><?= safe_output($t['agent_name'].' / '.$t['till_number'].' ('.$t['network_name'].')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Amount (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" min="1" step="any" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Date') ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="movement_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Reference No.') ?></label>
                            <input type="text" class="form-control" name="reference_no" placeholder="<?= t('Bank transfer / slip number') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Notes') ?></label>
                            <input type="text" class="form-control" name="notes">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i><?= t('Post Top-up') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Withdrawal Modal -->
<div class="modal fade" id="withdrawModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-arrow-up-circle me-1"></i><?= t('Float Withdrawal') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="withdrawForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="movement_type" value="float_withdrawal">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= t('Till') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="till_id" required>
                                <option value=""></option>
                                <?php foreach ($tills as $t): ?>
                                <option value="<?= $t['till_id'] ?>"><?= safe_output($t['agent_name'].' / '.$t['till_number'].' ('.$t['network_name'].')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Amount (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" min="1" step="any" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Date') ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="movement_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Reference No.') ?></label>
                            <input type="text" class="form-control" name="reference_no">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Notes') ?></label>
                            <input type="text" class="form-control" name="notes">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle me-1"></i><?= t('Post Withdrawal') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#floatTable')) {
        $('#floatTable').DataTable({responsive:false,scrollX:true,pageLength:25,order:[[2,'desc']],columnDefs:[{orderable:false,targets:0}],dom:'rtipB',
            language: { emptyTable: '<?= addslashes(t('No float movements found.')) ?>' },
            buttons:[{extend:'excelHtml5',className:'d-none',exportOptions:{columns:':not(:last-child)'}}],
            drawCallback:function(){renderCards(this.api().rows({page:'current'}).nodes());}
        });
    }
    function applyView(){if(window.innerWidth<768){$('#tableView').addClass('d-none');$('#cardView').removeClass('d-none');}else{$('#tableView').removeClass('d-none');$('#cardView').addClass('d-none');}}
    applyView(); $(window).on('resize',applyView);

    $('#topupModal, #withdrawModal').on('shown.bs.modal', function(){
        const modal=$(this);
        modal.find('.select2-static').each(function(){if(!$(this).hasClass('select2-hidden-accessible'))$(this).select2({theme:'bootstrap-5',dropdownParent:modal,placeholder:'<?= t('Select…') ?>',allowClear:true,width:'100%'});});
    });

    function submitFloatForm(formId) {
        $(formId).on('submit', function(e){
            e.preventDefault();
            const btn=$(this).find('[type=submit]'), orig=btn.html();
            btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
            $.ajax({url:'<?= buildUrl('api/mobile_money/save_float_movement.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
                success:r=>{if(r.success){Swal.fire({icon:'success',title:'<?= t('Posted!') ?>',text:r.message,timer:2000,showConfirmButton:false}).then(()=>location.reload());}else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:r.message});}},
                error:(xhr)=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:xhr.responseJSON?.message||'<?= t('Server error.') ?>'}),
                complete:()=>btn.prop('disabled',false).html(orig)
            });
        });
    }
    submitFloatForm('#topupForm'); submitFloatForm('#withdrawForm');
    $('.modal').on('hidden.bs.modal', function(){$(this).find('form')[0]?.reset();});
});

function renderCards(nodes) {
    if (!nodes.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No movements') ?></div>'); return; }
    let html = '';
    $(nodes).each(function (idx) {
        const $tr = $(this);
        const sno = idx + 1;
        const code = $tr.data('code'), date = $tr.data('date');
        const outlet = $tr.data('outlet'), type = $tr.data('type');
        const amount = $tr.data('amount'), status = $tr.data('status');
        const sBadge = status === 'posted' ? 'bg-success' : (status === 'void' ? 'bg-danger' : 'bg-secondary');
        const typeBadge = type.toLowerCase().includes('top') ? 'bg-success' : (type.toLowerCase().includes('withdraw') ? 'bg-warning text-dark' : 'bg-info text-dark');
        html += `<div class="col-12"><div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
          <div class="card-body p-3 pb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="mm-sno">${sno}</span>
              <span class="badge ${sBadge}" style="font-size:.73rem">${safeOutput(status.charAt(0).toUpperCase()+status.slice(1))}</span>
            </div>
            <div class="fw-semibold mb-2" style="font-size:.95rem"><code>${safeOutput(code)}</code></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Date') ?></span><span class="kv-val">${safeOutput(date)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Outlet / Till') ?></span><span class="kv-val">${safeOutput(outlet)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Type') ?></span><span class="kv-val"><span class="badge ${typeBadge}" style="font-size:.73rem">${safeOutput(type)}</span></span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Amount (TZS)') ?></span><span class="kv-val fw-bold">${safeOutput(amount)}</span></div>
          </div>
        </div></div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
