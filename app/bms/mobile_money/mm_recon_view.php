<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_reconciliation');

$can_edit = canEdit('mm_reconciliation');

$reconId = intval($_GET['id'] ?? 0);
if (!$reconId) { header("Location: " . getUrl('mobile_money/mm_reconciliation')); exit; }

$recon = $pdo->prepare("
    SELECT r.*,
           t.till_number, t.agent_id,
           a.agent_name,
           n.network_name, n.color_hex,
           u.name AS created_by_name
    FROM mm_reconciliations r
    JOIN mm_tills t    ON t.till_id    = r.till_id
    JOIN mm_agents a   ON a.agent_id   = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    LEFT JOIN users u  ON u.user_id    = r.created_by
    WHERE r.recon_id = ?
");
$recon->execute([$reconId]);
$recon = $recon->fetch(PDO::FETCH_ASSOC);
if (!$recon) { header("Location: " . getUrl('mobile_money/mm_reconciliation')); exit; }

$txnSummary = $pdo->prepare("
    SELECT txn_type, COUNT(*) AS cnt,
           SUM(principal_amount) AS volume,
           SUM(commission_earned) AS commission,
           SUM(cash_effect) AS net_cash,
           SUM(float_effect) AS net_float
    FROM mm_transactions
    WHERE till_id=? AND txn_date=? AND status='posted'
    GROUP BY txn_type ORDER BY txn_type
");
$txnSummary->execute([$recon['till_id'], $recon['recon_date']]);
$txnSummary = $txnSummary->fetchAll(PDO::FETCH_ASSOC);

$shifts = $pdo->prepare("
    SELECT s.*, u.name AS teller_name
    FROM mm_shifts s
    LEFT JOIN users u ON u.user_id = s.teller_user_id
    WHERE s.till_id=? AND DATE(s.opened_at)=?
    ORDER BY s.opened_at
");
$shifts->execute([$recon['till_id'], $recon['recon_date']]);
$shifts = $shifts->fetchAll(PDO::FETCH_ASSOC);

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View Recon', 'Viewed MM Recon #' . $recon['recon_code']);
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="<?= getUrl('mobile_money/mm_reconciliation') ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <i class="bi bi-clipboard-check text-info fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Reconciliation') ?>: <?= safe_output($recon['recon_code']) ?></h4>
        <?php $badge = ['open'=>'warning','resolved'=>'success','disputed'=>'danger','closed'=>'secondary']; ?>
        <span class="badge bg-<?= $badge[$recon['status']] ?? 'secondary' ?> ms-1"><?= safe_output(ucfirst($recon['status'])) ?></span>
        <div class="ms-auto d-flex gap-2">
            <?php if ($recon['status'] === 'open' && $can_edit): ?>
            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#resolveModal">
                <i class="bi bi-check2-all me-1"></i><?= t('Resolve') ?>
            </button>
            <button class="btn btn-sm btn-warning" onclick="markDisputed()">
                <i class="bi bi-flag me-1"></i><?= t('Mark Disputed') ?>
            </button>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= t('Print') ?></button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold bg-transparent"><?= t('Till Information') ?></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted"><?= t('Date') ?></td><td><?= safe_output($recon['recon_date']) ?></td></tr>
                        <tr><td class="text-muted"><?= t('Till') ?></td><td><?= safe_output($recon['till_number']) ?></td></tr>
                        <tr><td class="text-muted"><?= t('Agent') ?></td><td><?= safe_output($recon['agent_name']) ?></td></tr>
                        <tr><td class="text-muted"><?= t('Network') ?></td>
                            <td><span class="badge rounded-pill" style="background:<?= safe_output($recon['color_hex'] ?: '#6c757d') ?>"><?= safe_output($recon['network_name']) ?></span></td></tr>
                        <tr><td class="text-muted"><?= t('Created By') ?></td><td><?= safe_output($recon['created_by_name'] ?? '—') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header fw-bold bg-transparent"><?= t('Balance Reconciliation') ?></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <thead><tr><th></th><th class="text-end"><?= t('Cash') ?></th><th class="text-end"><?= t('E-Float') ?></th></tr></thead>
                        <tbody>
                            <tr><td class="text-muted"><?= t('Opening') ?></td>
                                <td class="text-end"><?= number_format((float)$recon['opening_cash']) ?></td>
                                <td class="text-end"><?= number_format((float)$recon['opening_float']) ?></td></tr>
                            <tr><td class="text-muted"><?= t('Computed') ?></td>
                                <td class="text-end"><?= number_format((float)$recon['computed_cash']) ?></td>
                                <td class="text-end"><?= number_format((float)$recon['computed_float']) ?></td></tr>
                            <?php if ($recon['actual_cash'] !== null): ?>
                            <tr><td class="text-muted"><?= t('Actual (counted)') ?></td>
                                <td class="text-end"><?= number_format((float)$recon['actual_cash']) ?></td>
                                <td class="text-end"><?= number_format((float)$recon['actual_float']) ?></td></tr>
                            <tr class="<?= ((float)$recon['cash_variance'] != 0 || (float)$recon['float_variance'] != 0) ? 'table-danger' : 'table-success' ?>">
                                <td class="fw-bold"><?= t('Variance') ?></td>
                                <td class="text-end fw-bold"><?= number_format((float)$recon['cash_variance']) ?></td>
                                <td class="text-end fw-bold"><?= number_format((float)$recon['float_variance']) ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header fw-bold bg-transparent"><?= t('Transaction Breakdown') ?></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?= t('Type') ?></th>
                            <th class="text-end"><?= t('Count') ?></th>
                            <th class="text-end"><?= t('Volume (TZS)') ?></th>
                            <th class="text-end"><?= t('Commission') ?></th>
                            <th class="text-end"><?= t('Cash Effect') ?></th>
                            <th class="text-end"><?= t('Float Effect') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($txnSummary)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3"><?= t('No transactions on this date') ?></td></tr>
                        <?php else: ?>
                        <?php $totCnt=0; $totVol=0; $totComm=0; $totCash=0; $totFloat=0;
                              foreach ($txnSummary as $row):
                                  $totCnt+=$row['cnt']; $totVol+=$row['volume'];
                                  $totComm+=$row['commission']; $totCash+=$row['net_cash']; $totFloat+=$row['net_float']; ?>
                        <tr>
                            <td><?= safe_output(ucwords(str_replace('_',' ',$row['txn_type']))) ?></td>
                            <td class="text-end"><?= $row['cnt'] ?></td>
                            <td class="text-end"><?= number_format((float)$row['volume']) ?></td>
                            <td class="text-end"><?= number_format((float)$row['commission']) ?></td>
                            <td class="text-end"><?= number_format((float)$row['net_cash']) ?></td>
                            <td class="text-end"><?= number_format((float)$row['net_float']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-dark fw-bold">
                            <td><?= t('Total') ?></td>
                            <td class="text-end"><?= $totCnt ?></td>
                            <td class="text-end"><?= number_format($totVol) ?></td>
                            <td class="text-end"><?= number_format($totComm) ?></td>
                            <td class="text-end"><?= number_format($totCash) ?></td>
                            <td class="text-end"><?= number_format($totFloat) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (!empty($shifts)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header fw-bold bg-transparent"><?= t('Shifts') ?></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?= t('Code') ?></th><th><?= t('Teller') ?></th>
                            <th><?= t('Opened') ?></th><th><?= t('Closed') ?></th>
                            <th class="text-end"><?= t('Cash Var') ?></th>
                            <th class="text-end"><?= t('Float Var') ?></th>
                            <th><?= t('Status') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shifts as $s): ?>
                        <tr>
                            <td><code><?= safe_output($s['shift_code']) ?></code></td>
                            <td><?= safe_output($s['teller_name'] ?? '—') ?></td>
                            <td><?= safe_output(substr($s['opened_at'],11,5)) ?></td>
                            <td><?= $s['closed_at'] ? safe_output(substr($s['closed_at'],11,5)) : '<span class="badge bg-warning">Open</span>' ?></td>
                            <td class="text-end <?= ($s['cash_variance'] ?? 0) != 0 ? 'text-danger' : '' ?>"><?= $s['cash_variance'] !== null ? number_format((float)$s['cash_variance']) : '—' ?></td>
                            <td class="text-end <?= ($s['float_variance'] ?? 0) != 0 ? 'text-danger' : '' ?>"><?= $s['float_variance'] !== null ? number_format((float)$s['float_variance']) : '—' ?></td>
                            <td><span class="badge bg-<?= $s['status']==='closed'?'success':($s['status']==='forced_close'?'warning':'primary') ?>"><?= safe_output(ucfirst($s['status'])) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recon['resolved_notes'])): ?>
    <div class="alert alert-info"><strong><?= t('Notes:') ?></strong> <?= safe_output($recon['resolved_notes']) ?></div>
    <?php endif; ?>
</div>

<?php if ($recon['status'] === 'open' && $can_edit): ?>
<div class="modal fade" id="resolveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check2-all me-1"></i><?= t('Resolve Reconciliation') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="resolveForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="recon_id" value="<?= $reconId ?>">
                    <input type="hidden" name="action" value="resolve">
                    <div id="resolve-message" class="mb-2"></div>
                    <p class="small text-muted"><?= t('Enter physically counted balances to compute variances.') ?></p>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label"><?= t('Actual Cash (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="actual_cash" min="0" step="0.01" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= t('Actual E-Float (TZS)') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="actual_float" min="0" step="0.01" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Notes') ?></label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i><?= t('Resolve') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function markDisputed() {
    const notes = prompt('<?= t('Reason for dispute:') ?>');
    if (notes === null) return;
    $.post('<?= buildUrl('api/mobile_money/update_reconciliation.php') ?>',
        { _csrf: '<?= csrf_token() ?>', recon_id: <?= $reconId ?>, action: 'disputed', notes: notes },
        function(res) {
            if (res.success) {
                Swal.fire({ icon: 'warning', title: '<?= t('Marked Disputed') ?>', timer: 1800, showConfirmButton: false }).then(() => location.reload());
            } else { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message }); }
        }, 'json');
}
$(document).ready(function() {
    $('#resolveForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= t('Saving...') ?>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/update_reconciliation.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Resolved!') ?>', timer: 1800, showConfirmButton: false }).then(() => location.reload());
                } else { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message }); }
            },
            error: function() { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: '<?= t('Server error.') ?>' }); },
            complete: function() { btn.prop('disabled', false).html(orig); }
        });
    });
});
</script>
<?php includeFooter(); ?>
