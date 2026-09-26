<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'MM Transactions';
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/mm_float_service.php';
autoEnforcePermission('mm_transactions');
includeHeader();

$can_create = canCreate('mm_transactions');
$can_view   = canView('mm_transactions');

// Filters
$filterNet    = intval($_GET['network_id'] ?? 0);
$filterType   = $_GET['txn_type'] ?? '';
$filterFrom   = $_GET['date_from'] ?? date('Y-m-01');
$filterTo     = $_GET['date_to']   ?? date('Y-m-d');
$filterTill   = intval($_GET['till_id'] ?? 0);

$networks = $pdo->query("SELECT network_id, network_code, network_name, color_hex FROM mm_networks WHERE status='active' ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$tills    = $pdo->query("SELECT t.till_id, t.till_number, a.agent_name FROM mm_tills t JOIN mm_agents a ON a.agent_id=t.agent_id WHERE t.status='active' ORDER BY a.agent_name, t.till_number")->fetchAll(PDO::FETCH_ASSOC);

$where = ["mt.status != 'void'", "mt.txn_date BETWEEN :date_from AND :date_to"];
$params = [':date_from' => $filterFrom, ':date_to' => $filterTo];
if ($filterNet)  { $where[] = 'mt.network_id = :net_id'; $params[':net_id'] = $filterNet; }
if ($filterType) { $where[] = 'mt.txn_type = :txn_type'; $params[':txn_type'] = $filterType; }
if ($filterTill) { $where[] = 'mt.till_id = :till_id'; $params[':till_id'] = $filterTill; }

$whereStr = 'WHERE ' . implode(' AND ', $where);

$txns = $pdo->prepare("
    SELECT mt.*, n.network_name, n.color_hex, ti.till_number, a.agent_name
    FROM mm_transactions mt
    JOIN mm_networks n ON n.network_id = mt.network_id
    JOIN mm_tills ti   ON ti.till_id   = mt.till_id
    JOIN mm_agents a   ON a.agent_id   = mt.agent_id
    $whereStr
    ORDER BY mt.txn_date DESC, mt.created_at DESC
    LIMIT 500
");
$txns->execute($params);
$txns = $txns->fetchAll(PDO::FETCH_ASSOC);

$totalAmount     = array_sum(array_column($txns, 'principal_amount'));
$totalCommission = array_sum(array_column($txns, 'commission_earned'));

$txnLabels = [
    'cash_in'=>'Cash In','cash_out'=>'Cash Out','send'=>'Send Money','bill_pay'=>'Bill Payment',
    'airtime'=>'Airtime','bank_to_wallet'=>'Bank→Wallet','wallet_to_bank'=>'Wallet→Bank','international'=>'International'
];

logActivity($pdo, $_SESSION['user_id'], 'View MM Transactions', 'Viewed transactions list');
?>

<div class="container-fluid mt-3 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-left-right text-primary me-2"></i><?= t('MM Transactions') ?></h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newTxnModal">
            <i class="bi bi-plus-circle me-1"></i> <?= t('New Transaction') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Date filter bar -->
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
            <select name="network_id" class="form-select form-select-sm">
                <option value=""><?= t('All Networks') ?></option>
                <?php foreach ($networks as $n): ?>
                <option value="<?= $n['network_id'] ?>" <?= $filterNet == $n['network_id'] ? 'selected' : '' ?>><?= safe_output($n['network_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="txn_type" class="form-select form-select-sm">
                <option value=""><?= t('All Types') ?></option>
                <?php foreach ($txnLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= t($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="till_id" class="form-select form-select-sm">
                <option value=""><?= t('All Tills') ?></option>
                <?php foreach ($tills as $t): ?>
                <option value="<?= $t['till_id'] ?>" <?= $filterTill == $t['till_id'] ? 'selected' : '' ?>><?= safe_output($t['agent_name'].' / '.$t['till_number']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="<?= getUrl('mm_transactions') ?>" class="btn btn-sm btn-outline-secondary"><?= t('Clear') ?></a>
        </div>
    </form>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= count($txns) ?></div>
                <div class="small text-muted"><?= t('Transactions') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold text-info"><?= number_format($totalAmount) ?></div>
                <div class="small text-muted"><?= t('Total Volume (TZS)') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-5 fw-bold text-success"><?= number_format($totalCommission) ?></div>
                <div class="small text-muted"><?= t('Commission Earned (TZS)') ?></div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="txnTable" class="table table-hover align-middle mb-0 w-100" style="font-size:.88rem">
                        <thead class="table-dark">
                            <tr>
                                <th><?= t('Code') ?></th>
                                <th><?= t('Date') ?></th>
                                <th><?= t('Network') ?></th>
                                <th><?= t('Type') ?></th>
                                <th><?= t('Outlet / Till') ?></th>
                                <th class="text-end"><?= t('Amount (TZS)') ?></th>
                                <th class="text-end"><?= t('Commission') ?></th>
                                <th><?= t('Customer') ?></th>
                                <th><?= t('Status') ?></th>
                                <th class="text-end"><?= t('') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($txns as $tx): ?>
                            <tr>
                                <td><code class="small"><?= safe_output($tx['txn_code']) ?></code></td>
                                <td class="small"><?= safe_output($tx['txn_date']) ?></td>
                                <td>
                                    <span class="d-inline-block me-1" style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($tx['color_hex'] ?: '#999') ?>"></span>
                                    <span class="small"><?= safe_output($tx['network_name']) ?></span>
                                </td>
                                <td>
                                    <span class="badge <?= in_array($tx['txn_type'], ['cash_in','bank_to_wallet']) ? 'bg-success' : (in_array($tx['txn_type'], ['cash_out','wallet_to_bank']) ? 'bg-warning text-dark' : 'bg-info text-dark') ?>" style="font-size:.72rem">
                                        <?= t($txnLabels[$tx['txn_type']] ?? $tx['txn_type']) ?>
                                    </span>
                                </td>
                                <td class="small"><?= safe_output($tx['agent_name'].' / '.$tx['till_number']) ?></td>
                                <td class="text-end fw-semibold"><?= number_format((float)$tx['principal_amount']) ?></td>
                                <td class="text-end <?= $tx['commission_earned'] > 0 ? 'text-success' : 'text-muted' ?>">
                                    <?= $tx['commission_earned'] > 0 ? number_format((float)$tx['commission_earned']) : '—' ?>
                                </td>
                                <td class="small text-muted">
                                    <?= $tx['customer_name'] ? safe_output($tx['customer_name']) : ($tx['customer_phone'] ? safe_output($tx['customer_phone']) : '—') ?>
                                    <?php if ($tx['kyc_required']): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">KYC</span><?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $tx['status'] === 'posted' ? 'bg-success' : 'bg-secondary' ?>" style="font-size:.7rem"><?= ucfirst(safe_output($tx['status'])) ?></span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= getUrl('mm_transaction_view') ?>?id=<?= $tx['mm_txn_id'] ?>" class="btn btn-sm btn-outline-secondary" title="<?= t('View') ?>"><i class="bi bi-eye"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($txns)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-5"><?= t('No transactions found for the selected filters.') ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- New Transaction Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="newTxnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i><?= t('New MM Transaction') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="newTxnForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="txn-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Outlet / Till') ?> <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="till_id" id="txn_till" required>
                                <option value=""></option>
                                <?php foreach ($tills as $t): ?>
                                <option value="<?= $t['till_id'] ?>"><?= safe_output($t['agent_name'].' / '.$t['till_number']) ?></option>
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
                            <label class="form-label"><?= t('Amount (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" required min="1" step="1" id="txn_amount" oninput="checkKYC(this.value)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Transaction Date') ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="txn_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Customer Name') ?></label>
                            <input type="text" class="form-control" name="customer_name" id="txn_cust_name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Customer Phone') ?></label>
                            <input type="text" class="form-control" name="customer_phone" id="txn_cust_phone" placeholder="+255...">
                        </div>
                        <div id="kyc_notice" class="col-12 d-none">
                            <div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle me-2"></i><?= t('BOT KYC required: customer name/phone is mandatory for TZS 1,000,000 or above.') ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?= t('Network Reference No.') ?></label>
                            <input type="text" class="form-control" name="network_ref" placeholder="<?= t('Network transaction ID') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?= t('Notes') ?></label>
                            <input type="text" class="form-control" name="notes">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-circle me-1"></i><?= t('Post Transaction') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#txnTable')) {
        $('#txnTable').DataTable({ responsive:false, scrollX:true, pageLength:50, order:[[1,'desc']], dom:'rtipB',
            buttons:[{extend:'excelHtml5',className:'d-none',exportOptions:{columns:':not(:last-child)'}}],
            drawCallback: function () { renderCards(this.api().rows({page:'current'}).data().toArray()); }
        });
    }
    function applyView() {
        if(window.innerWidth<768){$('#tableView').addClass('d-none');$('#cardView').removeClass('d-none');}
        else{$('#tableView').removeClass('d-none');$('#cardView').addClass('d-none');}
    }
    applyView(); $(window).on('resize',applyView);

    $('#newTxnModal').on('shown.bs.modal', function() {
        const modal=$(this);
        modal.find('.select2-static').each(function(){
            if(!$(this).hasClass('select2-hidden-accessible'))
                $(this).select2({theme:'bootstrap-5',dropdownParent:modal,placeholder:'<?= t('Select…') ?>',allowClear:true,width:'100%'});
        });
    });

    $('#newTxnForm').on('submit', function(e) {
        e.preventDefault();
        const btn=$(this).find('[type=submit]'), orig=btn.html();
        btn.prop('disabled',true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= t('Posting…') ?>');
        $.ajax({
            url:'<?= buildUrl('api/mobile_money/save_transaction.php') ?>',
            type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
            success: function(res) {
                if(res.success){
                    let msg=res.message;
                    if(res.commission>0) msg+='\n<?= t('Commission earned') ?>: TZS '+res.commission.toLocaleString();
                    Swal.fire({icon:'success',title:'<?= t('Transaction Posted!') ?>',text:msg,timer:2500,showConfirmButton:false}).then(()=>location.reload());
                } else { Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:res.message}); }
            },
            error: ()=>Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:'<?= t('Server error.') ?>'}),
            complete: ()=>btn.prop('disabled',false).html(orig)
        });
    });

    $('.modal').on('hidden.bs.modal', function(){$(this).find('form')[0]?.reset(); $('#kyc_notice').addClass('d-none');});
});

function checkKYC(val) {
    document.getElementById('kyc_notice').classList.toggle('d-none', parseFloat(val) < 1000000);
}

function renderCards(rows) {
    if(!rows.length){$('#cardView').html('<div class="col-12 text-center py-5 text-muted"><?= t('No transactions') ?></div>');return;}
    let html='';
    rows.forEach(r=>{
        html+=`<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${safeOutput(r[0])}</span><span class="text-muted small">${safeOutput(r[1])}</span></div>
                <div class="small">${safeOutput(r[3])} · ${safeOutput(r[2])}</div>
                <div class="fw-semibold">TZS ${parseFloat(r[5]).toLocaleString()}</div>
            </div></div></div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
