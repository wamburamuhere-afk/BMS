<?php
// scope-audit: skip — MM tables are agent-scoped via mm_user_agent_grants; no project/warehouse scope here.
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('mm_commissions');

$can_create = canCreate('mm_commissions');
$can_void   = canVoid('mm_commissions');

// --- Summary figures ---
// Earned: sum of commission_earned from posted transactions
$earned = (float)$pdo->query("
    SELECT COALESCE(SUM(t.commission_earned),0)
    FROM mm_transactions t
    WHERE t.status = 'posted'
")->fetchColumn();

// Received: sum of posted commission receipts
$received = (float)$pdo->query("
    SELECT COALESCE(SUM(amount_received),0)
    FROM mm_commissions_received
    WHERE status = 'posted'
")->fetchColumn();

$unreceived = max(0, $earned - $received);

// --- Per-network earned breakdown ---
$networkEarned = $pdo->query("
    SELECT n.network_id, n.network_name, n.color_hex,
           COALESCE(SUM(t.commission_earned),0) AS earned
    FROM mm_networks n
    LEFT JOIN mm_transactions t ON t.network_id = n.network_id AND t.status = 'posted'
    WHERE n.status = 'active'
    GROUP BY n.network_id
    ORDER BY n.sort_order
")->fetchAll(PDO::FETCH_ASSOC);

// --- Received records ---
$receivedRows = $pdo->query("
    SELECT cr.*, n.network_name, n.color_hex,
           a.account_name AS bank_name
    FROM mm_commissions_received cr
    JOIN mm_networks n ON n.network_id = cr.network_id
    LEFT JOIN accounts a ON a.account_id = cr.bank_account_id
    ORDER BY cr.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// --- For modal dropdowns ---
$networks = $pdo->query("SELECT network_id, network_name FROM mm_networks WHERE status='active' ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$bankAccounts = $pdo->query("SELECT account_id, account_code, account_name FROM accounts WHERE account_type='asset' AND status!='inactive' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);

includeHeader();
logActivity($pdo, $_SESSION['user_id'], 'View Commissions', 'Viewed Mobile Money Commissions');
?>
<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-coin text-warning fs-4"></i>
        <h4 class="mb-0 fw-bold"><?= t('MM Commissions') ?></h4>
        <?php if ($can_create): ?>
        <button class="btn btn-sm btn-success ms-auto" data-bs-toggle="modal" data-bs-target="#receiveModal">
            <i class="bi bi-plus-circle me-1"></i><?= t('Record Received') ?>
        </button>
        <?php endif; ?>
    </div>

    <!-- Summary tiles -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= number_format($earned) ?></div>
                <div class="small text-muted"><?= t('Total Earned (TZS)') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success"><?= number_format($received) ?></div>
                <div class="small text-muted"><?= t('Total Received (TZS)') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-<?= $unreceived > 0 ? 'warning' : 'secondary' ?>"><?= number_format($unreceived) ?></div>
                <div class="small text-muted"><?= t('Unreceived (TZS)') ?></div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="commTabs">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#earnedTab"><?= t('Earned by Network') ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#receivedTab"><?= t('Received Payments') ?></a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- Earned tab -->
        <div class="tab-pane fade show active" id="earnedTab">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th><?= t('Network') ?></th>
                            <th class="text-end"><?= t('Earned (TZS)') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($networkEarned as $row): ?>
                        <tr>
                            <td>
                                <span class="badge rounded-pill" style="background:<?= safe_output($row['color_hex'] ?: '#6c757d') ?>"><?= safe_output($row['network_name']) ?></span>
                            </td>
                            <td class="text-end fw-bold"><?= number_format((float)$row['earned']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Received tab -->
        <div class="tab-pane fade" id="receivedTab">
            <div class="table-responsive">
                <table id="receivedTable" class="table table-hover align-middle w-100">
                    <thead class="table-dark">
                        <tr>
                            <th><?= t('Network') ?></th>
                            <th><?= t('Period') ?></th>
                            <th class="text-end"><?= t('Amount (TZS)') ?></th>
                            <th><?= t('Bank Account') ?></th>
                            <th><?= t('Reference') ?></th>
                            <th><?= t('Status') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receivedRows as $row): ?>
                        <tr>
                            <td>
                                <span class="badge rounded-pill" style="background:<?= safe_output($row['color_hex'] ?: '#6c757d') ?>"><?= safe_output($row['network_name']) ?></span>
                            </td>
                            <td><?= safe_output($row['period_from']) ?> → <?= safe_output($row['period_to']) ?></td>
                            <td class="text-end fw-bold"><?= number_format((float)$row['amount_received']) ?></td>
                            <td><?= safe_output($row['bank_name'] ?? '—') ?></td>
                            <td><?= safe_output($row['reference_no'] ?? '—') ?></td>
                            <td>
                                <?php if ($row['status'] === 'posted'): ?>
                                    <span class="badge bg-success"><?= t('Posted') ?></span>
                                <?php elseif ($row['status'] === 'void'): ?>
                                    <span class="badge bg-danger"><?= t('Void') ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?= safe_output($row['status']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Record Commission Received Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="receiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-coin me-1"></i><?= t('Record Commission Received') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="receiveForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="receive-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Network') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="network_id" required>
                            <option value=""></option>
                            <?php foreach ($networks as $n): ?>
                            <option value="<?= $n['network_id'] ?>"><?= safe_output($n['network_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label"><?= t('Period From') ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="period_from" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= t('Period To') ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="period_to" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Amount Received (TZS)') ?> <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="amount_received" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Bank Account') ?> <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="bank_account_id" required>
                            <option value=""></option>
                            <?php foreach ($bankAccounts as $a): ?>
                            <option value="<?= $a['account_id'] ?>"><?= safe_output($a['account_code'] . ' — ' . $a['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Receipt Date') ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="receipt_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Reference No') ?></label>
                        <input type="text" class="form-control" name="reference_no" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Notes') ?></label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i><?= t('Post Receipt') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    if (!$.fn.DataTable.isDataTable('#receivedTable')) {
        $('#receivedTable').DataTable({ responsive: false, scrollX: true, pageLength: 25, order: [[0,'asc']] });
    }

    $('#receiveModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#receiveModal'), placeholder: '<?= t('Select...') ?>', allowClear: true, width: '100%' });
            }
        });
    });

    $('#receiveForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span><?= t('Posting...') ?>');
        $.ajax({
            url: '<?= buildUrl('api/mobile_money/save_commission_received.php') ?>',
            type: 'POST',
            data: new FormData(this),
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= t('Posted!') ?>', text: res.message, timer: 2000, showConfirmButton: false }).then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: res.message });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: '<?= t('Error') ?>', text: '<?= t('Server error.') ?>' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('.modal').on('hidden.bs.modal', function () {
        $(this).find('form')[0]?.reset();
        $(this).find('[id$="-message"]').html('');
    });
});
</script>
<?php includeFooter(); ?>
