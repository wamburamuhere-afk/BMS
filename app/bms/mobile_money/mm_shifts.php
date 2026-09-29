<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'MM Shifts';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_shifts');
includeHeader();

$can_open  = canCreate('mm_shifts');
$can_close = canEdit('mm_shifts');

// Current user's own open shift — used to swap Open/Close button
$myShiftStmt = $pdo->prepare("
    SELECT s.shift_id, s.shift_code, t.till_number, a.agent_name
    FROM mm_shifts s
    JOIN mm_tills t ON t.till_id = s.till_id
    JOIN mm_agents a ON a.agent_id = t.agent_id
    WHERE s.teller_user_id = ? AND s.status = 'open'
    LIMIT 1
");
$myShiftStmt->execute([$_SESSION['user_id']]);
$myOpenShift = $myShiftStmt->fetch(PDO::FETCH_ASSOC);

// Get tills the current user may open shifts on (Change A: filter by grants for non-admins)
if (isAdmin()) {
    $tillsForOpen = $pdo->query("
        SELECT t.till_id, t.till_number, a.agent_name, n.network_name, n.color_hex
        FROM mm_tills t
        JOIN mm_agents a ON a.agent_id = t.agent_id
        JOIN mm_networks n ON n.network_id = t.network_id
        WHERE t.status = 'active'
        ORDER BY a.agent_name, t.till_number
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $tillsStmt = $pdo->prepare("
        SELECT DISTINCT t.till_id, t.till_number, a.agent_name, n.network_name, n.color_hex
        FROM mm_tills t
        JOIN mm_agents a ON a.agent_id = t.agent_id
        JOIN mm_networks n ON n.network_id = t.network_id
        JOIN mm_user_agent_grants g ON g.agent_id = t.agent_id
            AND (g.till_id IS NULL OR g.till_id = t.till_id)
        WHERE t.status = 'active'
          AND g.user_id = ?
          AND g.can_open_shift = 1
        ORDER BY a.agent_name, t.till_number
    ");
    $tillsStmt->execute([$_SESSION['user_id']]);
    $tillsForOpen = $tillsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Change B: fetch currently busy tills (open shifts) — keyed by till_id
$busyTillsRaw = $pdo->query("
    SELECT s.till_id, s.shift_code,
           CONCAT(u.first_name, ' ', u.last_name) AS teller_name,
           TIME_FORMAT(s.opened_at, '%H:%i') AS opened_time
    FROM mm_shifts s
    JOIN users u ON u.user_id = s.teller_user_id
    WHERE s.status = 'open'
")->fetchAll(PDO::FETCH_ASSOC);
$busyTills = [];
foreach ($busyTillsRaw as $bt) {
    $busyTills[(int)$bt['till_id']] = $bt;
}

// Filters
$filterFrom   = $_GET['date_from'] ?? date('Y-m-01');
$filterTo     = $_GET['date_to']   ?? date('Y-m-d');
$filterStatus = $_GET['status']    ?? '';

$where  = ["s.opened_at >= :df", "s.opened_at <= :dt"];
$params = [':df' => $filterFrom . ' 00:00:00', ':dt' => $filterTo . ' 23:59:59'];
if ($filterStatus) { $where[] = 's.status = :status'; $params[':status'] = $filterStatus; }
if (!isAdmin()) { $where[] = 's.teller_user_id = :uid'; $params[':uid'] = $_SESSION['user_id']; }

$shifts = $pdo->prepare("
    SELECT s.*,
           t.till_number, a.agent_name, a.agent_code, n.network_name, n.color_hex,
           CONCAT(u.first_name, ' ', u.last_name) AS teller_name,
           CONCAT(uc.first_name, ' ', uc.last_name) AS closed_by_name,
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
        <?php if ($myOpenShift): ?>
        <button class="btn btn-danger btn-sm" onclick='closeShift(<?= htmlspecialchars(json_encode(['id'=>$myOpenShift['shift_id'],'code'=>$myOpenShift['shift_code'],'till_number'=>$myOpenShift['till_number'],'agent'=>$myOpenShift['agent_name']]),ENT_QUOTES) ?>)'>
            <i class="bi bi-stop-circle me-1"></i><?= t('Close Shift') ?> — <?= safe_output($myOpenShift['till_number']) ?>
        </button>
        <?php elseif ($can_open && count($tillsForOpen) > 0): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#openShiftModal">
            <i class="bi bi-play-circle me-1"></i><?= t('Open Shift') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Shift banner (Change C) -->
    <?php if ($myOpenShift): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center py-2 mb-3" style="border-radius:8px">
        <span><i class="bi bi-play-circle-fill me-2"></i>
        <strong><?= t('Active Shift') ?>:</strong> <?= safe_output($myOpenShift['shift_code']) ?>
        &nbsp;·&nbsp; <?= safe_output($myOpenShift['agent_name'].' / '.$myOpenShift['till_number']) ?>
        </span>
    </div>
    <?php else: ?>
    <div class="alert alert-warning py-2 mb-3" style="border-radius:8px">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= t('No active shift.') ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-3">
        <?php if (isAdmin()): ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-success"><?= $openCount ?></div>
                <div class="small text-muted"><?= t('Open Shifts') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-4 fw-bold text-primary"><?= $closedCount ?></div>
                <div class="small text-muted"><?= t('Closed Shifts') ?></div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-5 fw-bold text-info"><?= number_format(array_sum(array_column($shifts, 'txn_volume'))) ?></div>
                <div class="small text-muted"><?= t('Total Volume (TZS)') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 mm-stat-card">
                <div class="fs-5 fw-bold text-warning"><?= number_format(array_sum(array_column($shifts, 'txn_commission'))) ?></div>
                <div class="small text-muted"><?= t('Commission (TZS)') ?></div>
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
                    <table id="shiftsTable" class="table table-hover align-middle mb-0 w-100" style="font-size:.88rem">
                        <thead class="mm-thead">
                            <tr>
                                <th class="text-center" style="width:48px"><?= t('S/No') ?></th>
                                <th class="text-center"><?= t('Code') ?></th>
                                <th class="text-center"><?= t('Outlet / Till') ?></th>
                                <th class="text-center"><?= t('Teller') ?></th>
                                <th class="text-center"><?= t('Opened') ?></th>
                                <th class="text-center"><?= t('Closed') ?></th>
                                <th class="text-center"><?= t('Txns') ?></th>
                                <th class="text-center"><?= t('Volume (TZS)') ?></th>
                                <th class="text-center"><?= t('Cash Var.') ?></th>
                                <th class="text-center"><?= t('Float Var.') ?></th>
                                <th class="text-center"><?= t('Status') ?></th>
                                <th class="text-center"><?= t('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sno = 1; foreach ($shifts as $sh): ?>
                            <tr data-id="<?= (int)$sh['shift_id'] ?>" data-code="<?= htmlspecialchars($sh['shift_code']) ?>" data-outlet="<?= htmlspecialchars($sh['agent_name'].' / '.$sh['till_number']) ?>" data-teller="<?= htmlspecialchars($sh['teller_name'] ?: '—') ?>" data-opened="<?= date('d M H:i', strtotime($sh['opened_at'])) ?>" data-volume="<?= $sh['txn_volume'] ? number_format((float)$sh['txn_volume']) : '—' ?>" data-status="<?= htmlspecialchars($sh['status']) ?>" data-can-close="<?= ($sh['status'] === 'open' && $can_close) ? '1' : '0' ?>" data-shift='<?= htmlspecialchars(json_encode(['id'=>$sh['shift_id'],'code'=>$sh['shift_code'],'till_number'=>$sh['till_number'],'agent'=>$sh['agent_name']]),ENT_QUOTES) ?>'>
                                <td class="text-center text-muted small"><?= $sno++ ?></td>
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
                                <td class="text-center">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:140px;font-size:.85rem">
                                            <li><a class="dropdown-item" href="<?= getUrl('mm_shift_report') ?>?id=<?= $sh['shift_id'] ?>"><i class="bi bi-printer me-2 text-secondary"></i><?= t('Report') ?></a></li>
                                            <?php if ($sh['status'] === 'open' && $can_close): ?>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick='closeShift(<?= htmlspecialchars(json_encode(['id'=>$sh['shift_id'],'code'=>$sh['shift_code'],'till_number'=>$sh['till_number'],'agent'=>$sh['agent_name']]),ENT_QUOTES) ?>);return false'><i class="bi bi-stop-circle me-2"></i><?= t('Close') ?></a></li>
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

<!-- Open Shift Modal -->
<?php if ($can_open): ?>
<div class="modal fade" id="openShiftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-play-circle me-1"></i><?= t('Open Shift') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="openShiftForm" method="post" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?= t('Till') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="till_id" id="open_till_select" required>
                                <option value=""></option>
                                <?php foreach ($tillsForOpen as $t):
                                    $busy = $busyTills[(int)$t['till_id']] ?? null; ?>
                                <option value="<?= $t['till_id'] ?>" <?= $busy ? 'disabled' : '' ?>>
                                    <?= safe_output($t['agent_name'].' / '.$t['till_number'].' ('.$t['network_name'].')') ?>
                                    <?= $busy ? ' — '.t('in use by').' '.safe_output($busy['teller_name']).' '.t('since').' '.$busy['opened_time'] : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Opening Cash (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="opening_cash" min="0" step="100" required>
                        </div>
                        <div class="col-md-6">
                            <label id="open_float_label" class="form-label"><?= t('Opening Float (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="opening_float" id="open_float_input" min="0" step="100" required placeholder="<?= t('Select till first…') ?>">
                            <div id="open_float_hint" class="form-text d-none"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-play-circle me-1"></i><?= t('Open Shift') ?></button>
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
            <form id="closeShiftForm" method="post" autocomplete="off">
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
function safeOutput(s) { return s == null ? '' : String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }

$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#shiftsTable')) {
        $('#shiftsTable').DataTable({ responsive:false, scrollX:true, pageLength:25, order:[[4,'desc']], columnDefs:[{orderable:false,targets:0}], dom:'rtipB',
            language: { emptyTable: '<?= addslashes(t('No shifts found.')) ?>' },
            buttons:[{extend:'excelHtml5',className:'d-none',exportOptions:{columns:':not(:last-child)'}}],
            drawCallback: function() { renderCards(this.api().rows({page:'current'}).nodes()); applyView(); }
        });
    }
    function applyView(){if(window.innerWidth<768){$('#tableView').addClass('d-none');$('#cardView').removeClass('d-none');}else{$('#tableView').removeClass('d-none');$('#cardView').addClass('d-none');}}
    applyView(); $(window).on('resize',applyView);

    <?php if (($_GET['action'] ?? '') === 'open' && $can_open && !$myOpenShift && count($tillsForOpen) > 0): ?>
    new bootstrap.Modal(document.getElementById('openShiftModal')).show();
    <?php endif; ?>

    $('#openShiftModal').on('shown.bs.modal', function(){
        const modal=$(this);
        modal.find('.select2-static').each(function(){
            if(!$(this).hasClass('select2-hidden-accessible'))
                $(this).select2({theme:'bootstrap-5',dropdownParent:modal,placeholder:'<?= t('Select till…') ?>',allowClear:true,width:'100%'});
        });
    });

    // Auto-fill Opening Float when till is selected (Change: auto-float)
    $(document).on('select2:select', '#open_till_select', function(){
        const tillId = $(this).val();
        if (!tillId) { $('#open_float_hint').addClass('d-none').text(''); return; }
        $('#open_float_hint').removeClass('d-none').html('<span class="text-muted"><?= t('Fetching expected float…') ?></span>');
        $.getJSON('<?= buildUrl('api/mobile_money/get_till_float.php') ?>', {till_id: tillId}, function(res){
            if (!res.success) { $('#open_float_hint').html('<span class="text-danger">'+res.message+'</span>'); return; }
            const $inp = $('#open_float_input');
            $inp.val(res.expected_float);
            if (res.is_new_till) {
                $inp.addClass('border-warning');
                $('#open_float_label').html('<?= t('Opening Float (TZS)') ?> <span class="text-danger">*</span> <small class="text-warning fw-normal"><?= t('(new till — enter actual SIM balance)') ?></small>');
                $('#open_float_hint').html('<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= t('No prior snapshot. Enter the current SIM e-money balance.') ?></span>');
            } else {
                $inp.removeClass('border-warning');
                const dt = res.last_snapshot_at ? new Date(res.last_snapshot_at).toLocaleString() : '';
                $('#open_float_label').html('<?= t('Opening Float (TZS)') ?> <span class="text-danger">*</span>');
                $('#open_float_hint').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i><?= t('Expected from last snapshot') ?>'+(dt?' ('+dt+')':'')+'. <?= t('Adjust if needed.') ?></span>');
            }
        }).fail(function(){
            $('#open_float_hint').html('<span class="text-danger"><?= t('Could not load expected float.') ?></span>');
        });
    });

    $(document).on('select2:clear', '#open_till_select', function(){
        $('#open_float_input').val('').removeClass('border-warning');
        $('#open_float_label').html('<?= t('Opening Float (TZS)') ?> <span class="text-danger">*</span>');
        $('#open_float_hint').addClass('d-none').text('');
    });

    $('#openShiftForm').on('submit', function(e){
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/open_shift.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success: function(r) {
                btn.prop('disabled',false).html(orig);
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('openShiftModal'))?.hide();
                    Swal.fire({
                        icon: 'success',
                        title: '<?= t('Shift Opened!') ?>',
                        html: r.message.replace(/\n/g,'<br>'),
                        timer: 4000,
                        timerProgressBar: true,
                        showConfirmButton: true,
                        confirmButtonText: '<?= t('OK') ?>'
                    }).then(() => location.reload());
                } else {
                    Swal.fire({icon:'error', title:'<?= t('Error') ?>', text: r.message});
                }
            },
            error: (xhr) => {
                btn.prop('disabled',false).html(orig);
                Swal.fire({icon:'error', title:'<?= t('Error') ?>', text: xhr.responseJSON?.message||'<?= t('Server error.') ?>'});
            }
        });
    });

    $('#closeShiftForm').on('submit', function(e){
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span>');
        $.ajax({url:'<?= buildUrl('api/mobile_money/close_shift.php') ?>',type:'POST',data:new FormData(this),contentType:false,processData:false,dataType:'json',
            success: function(r) {
                btn.prop('disabled',false).html(orig);
                if (r.success) {
                    bootstrap.Modal.getInstance(document.getElementById('closeShiftModal'))?.hide();
                    const cashVar  = parseFloat(r.cash_variance  ?? 0);
                    const floatVar = parseFloat(r.float_variance ?? 0);
                    const hasVar   = cashVar !== 0 || floatVar !== 0;
                    const cashClass  = cashVar  === 0 ? 'text-muted' : (cashVar  > 0 ? 'text-warning' : 'text-danger');
                    const floatClass = floatVar === 0 ? 'text-muted' : (floatVar > 0 ? 'text-warning' : 'text-danger');
                    let html = '<p><?= t('Shift closed successfully.') ?></p>';
                    html += '<div class="d-flex justify-content-between border-top pt-2 mt-2">';
                    html += '<span><?= t('Cash Variance:') ?></span><span class="fw-bold '+cashClass+'">'+(cashVar >= 0 ? '+' : '')+cashVar.toLocaleString()+' TZS</span></div>';
                    html += '<div class="d-flex justify-content-between">';
                    html += '<span><?= t('Float Variance:') ?></span><span class="fw-bold '+floatClass+'">'+(floatVar >= 0 ? '+' : '')+floatVar.toLocaleString()+' TZS</span></div>';
                    Swal.fire({
                        icon: hasVar ? 'warning' : 'success',
                        title: '<?= t('Shift Closed!') ?>',
                        html: html,
                        showConfirmButton: true,
                        confirmButtonText: '<?= t('OK') ?>'
                    }).then(() => location.reload());
                } else {
                    Swal.fire({icon:'error', title:'<?= t('Error') ?>', text: r.message});
                }
            },
            error: (xhr) => {
                btn.prop('disabled',false).html(orig);
                Swal.fire({icon:'error', title:'<?= t('Error') ?>', text: xhr.responseJSON?.message||'<?= t('Server error.') ?>'});
            }
        });
    });

    $('.modal').on('hidden.bs.modal', function(){
        $(this).find('form')[0]?.reset();
        if (this.id === 'openShiftModal') {
            $('#open_float_input').removeClass('border-warning');
            $('#open_float_label').html('<?= t('Opening Float (TZS)') ?> <span class="text-danger">*</span>');
            $('#open_float_hint').addClass('d-none').text('');
        }
    });
});

function closeShift(s){
    $('#close_shift_id').val(s.id);
    $('#close_shift_info').html('<strong><?= t('Shift:') ?> '+safeOutput(s.code)+'</strong> · '+safeOutput(s.agent)+' / '+safeOutput(s.till_number));
    new bootstrap.Modal(document.getElementById('closeShiftModal')).show();
}

function renderCards(nodes) {
    if (!nodes.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No shifts') ?></div>'); return; }
    let html = '';
    $(nodes).each(function (idx) {
        const $tr = $(this);
        const id = $tr.data('id'), sno = idx + 1;
        const code = $tr.data('code'), outlet = $tr.data('outlet');
        const teller = $tr.data('teller'), opened = $tr.data('opened');
        const volume = $tr.data('volume'), status = $tr.data('status');
        const canClose = $tr.data('can-close') == 1, shiftData = $tr.data('shift');
        const sBadge = status === 'open' ? 'bg-success' : (status === 'forced_close' ? 'bg-danger' : 'bg-secondary');
        html += `<div class="col-12"><div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden">
          <div class="card-body p-3 pb-2">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="mm-sno">${sno}</span>
              <span class="badge ${sBadge}" style="font-size:.73rem">${safeOutput(status.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase()))}</span>
            </div>
            <div class="fw-semibold mb-2" style="font-size:.95rem"><code>${safeOutput(code)}</code></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Outlet / Till') ?></span><span class="kv-val">${safeOutput(outlet)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Teller') ?></span><span class="kv-val">${safeOutput(teller)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Opened') ?></span><span class="kv-val">${safeOutput(opened)}</span></div>
            <div class="mm-kv"><span class="kv-lbl"><?= t('Volume (TZS)') ?></span><span class="kv-val fw-bold">${safeOutput(volume)}</span></div>
          </div>
          <div class="mm-card-foot">
            <a href="<?= getUrl('mm_shift_report') ?>?id=${id}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i><?= t('Report') ?></a>
            ${canClose ? `<button class="btn btn-sm btn-outline-danger" onclick='closeShift(${JSON.stringify(shiftData)})'><i class="bi bi-stop-circle me-1"></i><?= t('Close') ?></button>` : ''}
          </div>
        </div></div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
