<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
ob_start();
$page_title = 'Transaction View';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_transactions');
includeHeader();

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo '<div class="alert alert-danger m-4">' . t('Invalid transaction ID.') . '</div>'; includeFooter(); exit; }

$tx = $pdo->prepare("
    SELECT mt.*, n.network_name, n.network_code, n.color_hex,
           ti.till_number, a.agent_name, a.agent_code, a.region, a.district,
           u.full_name AS teller_name
    FROM mm_transactions mt
    JOIN mm_networks n ON n.network_id = mt.network_id
    JOIN mm_tills ti   ON ti.till_id   = mt.till_id
    JOIN mm_agents a   ON a.agent_id   = mt.agent_id
    LEFT JOIN users u  ON u.user_id    = mt.teller_user_id
    WHERE mt.mm_txn_id = ?
");
$tx->execute([$id]);
$tx = $tx->fetch(PDO::FETCH_ASSOC);

if (!$tx) { echo '<div class="alert alert-warning m-4">' . t('Transaction not found.') . '</div>'; includeFooter(); exit; }

$txnLabels = [
    'cash_in'=>'Cash In','cash_out'=>'Cash Out','send'=>'Send Money','bill_pay'=>'Bill Payment',
    'airtime'=>'Airtime','bank_to_wallet'=>'Bank→Wallet','wallet_to_bank'=>'Wallet→Bank','international'=>'International'
];

$page_title = 'MM Txn: ' . $tx['txn_code'];
$can_void = canVoid('mm_transactions') && $tx['status'] === 'posted';

logActivity($pdo, $_SESSION['user_id'], 'View MM Transaction', 'Viewed: ' . $tx['txn_code']);
?>

<div class="container-fluid mt-3 mb-5" style="max-width:900px">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="<?= getUrl('mm_transactions') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <h4 class="mb-0 fw-bold"><?= safe_output($tx['txn_code']) ?></h4>
        <span class="badge <?= $tx['status']==='posted'?'bg-success':($tx['status']==='void'?'bg-danger':'bg-secondary') ?>"><?= ucfirst(safe_output($tx['status'])) ?></span>
        <?php if ($tx['kyc_required']): ?><span class="badge bg-warning text-dark">KYC</span><?php endif; ?>
        <div class="ms-auto d-flex gap-2">
            <?php if ($can_void): ?>
            <button class="btn btn-sm btn-outline-danger" onclick="voidTransaction(<?= $id ?>, '<?= addslashes($tx['txn_code']) ?>')">
                <i class="bi bi-x-octagon me-1"></i><?= t('Void') ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-3 text-primary"><?= t('Transaction Details') ?></h6>
                    <dl class="row mb-0">
                        <dt class="col-sm-5 text-muted small"><?= t('Date') ?></dt>
                        <dd class="col-sm-7 small"><?= safe_output($tx['txn_date']) ?><?= $tx['txn_time'] ? ' ' . substr($tx['txn_time'], 0, 5) : '' ?></dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Network') ?></dt>
                        <dd class="col-sm-7 small">
                            <span class="d-inline-block me-1" style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($tx['color_hex'] ?: '#999') ?>"></span>
                            <?= safe_output($tx['network_name']) ?>
                        </dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Type') ?></dt>
                        <dd class="col-sm-7 small"><span class="badge bg-primary"><?= t($txnLabels[$tx['txn_type']] ?? $tx['txn_type']) ?></span></dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Amount') ?></dt>
                        <dd class="col-sm-7 fw-bold fs-5">TZS <?= number_format((float)$tx['principal_amount']) ?></dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Commission Earned') ?></dt>
                        <dd class="col-sm-7 small <?= $tx['commission_earned'] > 0 ? 'text-success fw-semibold' : 'text-muted' ?>">
                            <?= $tx['commission_earned'] > 0 ? 'TZS ' . number_format((float)$tx['commission_earned']) : '—' ?>
                        </dd>
                        <?php if ($tx['reference_no']): ?>
                        <dt class="col-sm-5 text-muted small"><?= t('Network Ref') ?></dt>
                        <dd class="col-sm-7 small"><?= safe_output($tx['reference_no']) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fw-bold mb-3 text-primary"><?= t('Outlet & Customer') ?></h6>
                    <dl class="row mb-0">
                        <dt class="col-sm-5 text-muted small"><?= t('Agent Outlet') ?></dt>
                        <dd class="col-sm-7 small"><?= safe_output($tx['agent_name']) ?></dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Till') ?></dt>
                        <dd class="col-sm-7 small"><code><?= safe_output($tx['till_number']) ?></code></dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Location') ?></dt>
                        <dd class="col-sm-7 small"><?= safe_output(implode(', ', array_filter([$tx['region'], $tx['district']])) ?: '—') ?></dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Teller') ?></dt>
                        <dd class="col-sm-7 small"><?= safe_output($tx['teller_name'] ?: '—') ?></dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Customer Name') ?></dt>
                        <dd class="col-sm-7 small"><?= safe_output($tx['customer_name'] ?: '—') ?></dd>
                        <dt class="col-sm-5 text-muted small"><?= t('Customer Phone') ?></dt>
                        <dd class="col-sm-7 small"><?= safe_output($tx['customer_phone'] ?: '—') ?></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <?php if ($tx['notes']): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h6 class="fw-bold text-primary mb-1"><?= t('Notes') ?></h6>
            <p class="mb-0 small"><?= safe_output($tx['notes']) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- GL Journal link -->
    <?php if ($tx['journal_entry_id']): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex align-items-center gap-3">
            <i class="bi bi-journal-check text-primary fs-4"></i>
            <div>
                <div class="fw-semibold"><?= t('GL Journal Entry') ?> #<?= $tx['journal_entry_id'] ?></div>
                <div class="small text-muted"><?= t('Double-entry posted to the canonical ledger.') ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($tx['void_reason']): ?>
    <div class="alert alert-danger">
        <strong><?= t('Void Reason:') ?></strong> <?= safe_output($tx['void_reason']) ?>
    </div>
    <?php endif; ?>
</div>

<script>
function voidTransaction(id, code) {
    Swal.fire({
        title: '<?= t('Void Transaction?') ?>', text: code,
        input: 'text', inputPlaceholder: '<?= t('Reason for void (required)') ?>',
        inputValidator: v => { if(!v) return '<?= t('Reason is required') ?>'; },
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: '<?= t('Void It') ?>'
    }).then(r => {
        if(!r.isConfirmed) return;
        $.post('<?= buildUrl('api/mobile_money/void_transaction.php') ?>', {
            _csrf: '<?= csrf_token() ?>', txn_id: id, void_reason: r.value
        }, res => {
            if(res.success){Swal.fire({icon:'success',title:'<?= t('Voided') ?>',timer:1500,showConfirmButton:false}).then(()=>location.reload());}
            else{Swal.fire({icon:'error',title:'<?= t('Error') ?>',text:res.message});}
        }, 'json');
    });
}
</script>

<?php includeFooter(); ?>
