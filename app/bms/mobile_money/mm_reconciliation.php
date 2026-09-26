<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_reconciliation');

$can_create = canCreate('mm_reconciliation');
$can_edit   = canEdit('mm_reconciliation');

$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(status='open') AS open_count,
           SUM(status='resolved') AS resolved_count,
           SUM(status='disputed') AS disputed_count
    FROM mm_reconciliations
")->fetch(PDO::FETCH_ASSOC);

$recons = $pdo->query("
    SELECT r.*,
           t.till_number, a.agent_name,
           n.network_name, n.color_hex,
           u.name AS created_by_name
    FROM mm_reconciliations r
    JOIN mm_tills t    ON t.till_id    = r.till_id
    JOIN mm_agents a   ON a.agent_id   = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    LEFT JOIN users u  ON u.user_id    = r.created_by
    ORDER BY r.recon_date DESC, r.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$tills = $pdo->query("
    SELECT t.till_id, t.till_number, a.agent_name, n.network_name
    FROM mm_tills t
    JOIN mm_agents a   ON a.agent_id   = t.agent_id
    JOIN mm_networks n ON n.network_id = t.network_id
    WHERE t.status = 'active'
    ORDER BY a.agent_name, t.till_number
")->fetchAll(PDO::FETCH_ASSOC);

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View Reconciliations', 'Viewed MM Daily Reconciliations');
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-clipboard-check text-info fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('Daily Reconciliation') ?></h4>
        <?php if ($can_create): ?>
        <button class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#startReconModal">
            <i class="bi bi-plus-circle me-1"></i><?= t('Start Reconciliation') ?>
        </button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= (int)$stats['total'] ?></div>
                <div class="small text-muted"><?= t('Total') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-warning"><?= (int)$stats['open_count'] ?></div>
                <div class="small text-muted"><?= t('Open') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success"><?= (int)$stats['resolved_count'] ?></div>
                <div class="small text-muted"><?= t('Resolved') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-danger"><?= (int)$stats['disputed_count'] ?></div>
                <div class="small text-muted"><?= t('Disputed') ?></div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table id="reconTable" class="table table-hover align-middle w-100">
            <thead class="table-dark">
                <tr>
                    <th><?= t('Code') ?></th>
                    <th><?= t('Date') ?></th>
                    <th><?= t('Till') ?></th>
                    <th><?= t('Agent') ?></th>
                    <th class="text-end"><?= t('Cash Var') ?></th>
                    <th class="text-end"><?= t('Float Var') ?></th>
                    <th><?= t('Status') ?></th>
                    <th class="text-end"><?= t('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recons as $r): ?>
                <tr>
                    <td><code><?= safe_output($r['recon_code']) ?></code></td>
                    <td><?= safe_output($r['recon_date']) ?></td>
                    <td>
                        <span class="badge rounded-pill" style="background:<?= safe_output($r['color_hex'] ?: '#6c757d') ?>"><?= safe_output($r['network_name']) ?></span>
                        <?= safe_output($r['till_number']) ?>
                    </td>
                    <td><?= safe_output($r['agent_name']) ?></td>
                    <td class="text-end <?= (float)($r['cash_variance'] ?? 0) != 0 ? 'text-danger fw-bold' : '' ?>">
                        <?= $r['cash_variance'] !== null ? number_format((float)$r['cash_variance']) : '—' ?>
                    </td>
                    <td class="text-end <?= (float)($r['float_variance'] ?? 0) != 0 ? 'text-danger fw-bold' : '' ?>">
                        <?= $r['float_variance'] !== null ? number_format((float)$r['float_variance']) : '—' ?>
                    </td>
                    <td>
                        <?php $badge = ['open'=>'warning','resolved'=>'success','disputed'=>'danger','closed'=>'secondary']; ?>
                        <span class="badge bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= safe_output(ucfirst($r['status'])) ?></span>
                    </td>
                    <td class="text-end">
                        <a href="<?= getUrl('mobile_money/mm_recon_view') ?>?id=<?= $r['recon_id'] ?>" class="btn btn-sm btn-outline-info">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($can_create): ?>
<div class="modal fade" id="startReconModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-clipboard-plus me-1"></i><?= t('Start Reconciliation') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="startReconForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="start-recon-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Till') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="till_id" required>
                            <option value=""></option>
                            <?php foreach ($tills as $ti): ?>
                            <option value="<?= $ti['till_id'] ?>"><?= safe_output($ti['agent_name'] . ' / ' . $ti['till_number'] . ' (' . $ti['network_name'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Reconciliation Date') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="recon_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Start') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#reconTable')) {
        $('#reconTable').DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [[1,'desc']] });
    }
    $('#startReconModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#startReconModal'), placeholder: '<?= t('Select...') ?>', allowClear: true, width: '100%' });
            }
        });
    });
    $('#startReconForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= t('Starting...') ?>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_reconciliation.php') ?>',
            type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Started!') ?>', timer: 1800, showConfirmButton: false })
                        .then(() => window.location = '<?= getUrl('mobile_money/mm_recon_view') ?>?id=' + res.recon_id);
                } else { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: '<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });
    $('.modal').on('hidden.bs.modal', function () { $(this).find('form')[0]?.reset(); $(this).find('[id$="-message"]').html(''); });
});
</script>
<?php includeFooter(); ?>
